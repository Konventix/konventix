<?php

declare(strict_types=1);

namespace Konventix\Identity\Providers;

use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectResolver;
use Konventix\Identity\Actions\AuthenticateUser;
use Konventix\Identity\Actions\CreateNewUser;
use Konventix\Identity\Actions\ResetUserPassword;
use Konventix\Identity\Console\ReplayProfilesCommand;
use Konventix\Identity\Enums\Permission;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Gdpr\IdentitySubjectResolver;
use Konventix\Identity\Http\Middleware\EnsureCredentialsAreStrings;
use Konventix\Identity\Http\Middleware\EnsureSignupsOpen;
use Konventix\Identity\Http\Responses\ConsentAwareLoginResponse;
use Konventix\Identity\Http\Responses\ConsentAwareTwoFactorLoginResponse;
use Konventix\Identity\Listeners\RecordBrandMembership;
use Konventix\Identity\Listeners\SendWelcomeOnVerified;
use Konventix\Identity\Models\User;
use Konventix\Identity\Social\SocialProviderRegistry;
use Konventix\Identity\Social\SteamProvider;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Cookies\CookieDeclaration;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Enums\CookieCategory;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\OperatorPresence;
use Konventix\Platform\Operators\OperatorSettings;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Steam\SteamExtendSocialite;

/**
 * Kernel module. Owns authentication, the slim User, 2FA and sessions.
 *
 * All Fortify wiring lives here rather than in a central application provider
 * (ADR-0003).
 */
class IdentityServiceProvider extends ModuleServiceProvider
{
    /**
     * Kernel module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'identity';
    }

    public function boot(): void
    {
        parent::boot();

        $this->configureFortifyActions();
        $this->configureFortifyResponses();
        $this->configureFortifyViews();
        $this->configureRateLimiting();
        $this->throttleUnhookedFortifyRoutes();

        // The signup gate as a named alias for the module's own social
        // completion routes (routes/web/identity.php); Fortify's register
        // routes get the class wrapped on above.
        Route::aliasMiddleware('signups-open', EnsureSignupsOpen::class);

        $this->commands([ReplayProfilesCommand::class]);
    }

    /**
     * ADR-0016: registration and login establish the user↔brand membership.
     *
     * `Registered => SendEmailVerificationNotification` is deliberately NOT
     * registered here, despite being Laravel's own default listener: every
     * `bootstrap/app.php` built on `Application::configure()` chains
     * `->withEvents()` unconditionally (`Illuminate\Foundation\Application::
     * configure()`), which registers the framework's own base
     * `Illuminate\Foundation\Support\Providers\EventServiceProvider` — an
     * empty `$listen` array, but its `booted()` hook always calls
     * `configureEmailVerification()`, which wires exactly this pair whenever
     * it is not already present. Registering it again here does not fail
     * (Laravel's dispatcher has no dedup), it doubles the send — the module
     * boots once, but the account then gets two identical verification
     * mails, invisible to an `assertSentTo()`-style assertion that only
     * checks "was one sent" (`AuthMailTest.php` is what caught it, asserting
     * an exact pushed count). `SendEmailVerificationNotification` calls
     * `$event->user->sendEmailVerificationNotification()` regardless of who
     * registered it, so `AuthMailer::sendVerification()` still runs — this
     * relies on Laravel's own wiring instead of duplicating it.
     */
    protected function registerListeners(): void
    {
        Event::listen(Login::class, RecordBrandMembership::class);
        Event::listen(Registered::class, RecordBrandMembership::class);

        // ADR-0048 point 5: the welcome that follows the first verification.
        Event::listen(Verified::class, SendWelcomeOnVerified::class);
    }

    /**
     * The social drivers this deployment offers (decision log D-IDN-01 as
     * amended: Steam only). Registering a driver here is the whole cost of
     * adding one — nothing downstream of `SocialProviderRegistry` names a
     * provider.
     *
     * Safe as a singleton: the drivers hold no request state, resolving
     * `request()` per call instead (see `SteamProvider::driver()`).
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(SocialProviderRegistry::class, function ($app): SocialProviderRegistry {
            $registry = new SocialProviderRegistry;

            $registry->register($app->make(SteamProvider::class));

            return $registry;
        });

        // Community Socialite providers are not auto-discovered the way the
        // built-in ones are: the Manager package fires `SocialiteWasCalled`
        // exactly once, after boot, and each provider's listener extends
        // whatever factory instance exists at that moment. That was a plain
        // `Event::listen` here, and it passed every test — but Octane flushes
        // the factory before every request (config/octane.php `flush`, so a
        // cached driver holds no stale request), and the instance resolved
        // afterwards has never heard of Steam: "Driver [steam] not supported"
        // on every login of the dev cluster (found 2026-09-18). Extending on
        // every resolution covers the boot-time instance and each post-flush
        // one alike. `afterResolving` runs once the container has stored the
        // singleton, so the package's own `make(Factory)` inside returns this
        // very instance instead of recursing; the Manager's boot-time event
        // then merely re-extends an instance that already knows the driver.
        $this->app->afterResolving(SocialiteFactory::class, function (): void {
            (new SteamExtendSocialite)->handle($this->app->make(SocialiteWasCalled::class));
        });

        // Compliance's public verification link resolves an address to an
        // account without ever importing Identity's User model (ADR-0004) —
        // see SubjectResolver's docblock.
        $this->app->bind(SubjectResolver::class, IdentitySubjectResolver::class);
    }

    /**
     * The "remember me" cookie — set only when a person ticks the box, since
     * that tick is what makes persistent login not exempt under WP194 §3.2.
     */
    protected function registerCookies(): void
    {
        $this->app->make(CookieRegistry::class)->register(
            new CookieDeclaration('remember_web_*', 'Keeps you signed in across browser sessions — set only when you tick "remember me". The real cookie name carries a hash suffix Laravel generates per guard, hence the wildcard.', CookieCategory::Necessary, '5 years', 'identity'),
        );
    }

    /**
     * Identity's operator-console entry (ADR-0029).
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'identity',
                label: 'Users',
                url: '/admin/users',
                icon: 'users',
                order: 20,
                permission: Permission::ViewUsers->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    private function configureFortifyActions(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Replaces Fortify's default credential lookup entirely (SPEC.md
        // "Account lifecycle": a locked account must not authenticate even
        // with the correct password — see AuthenticateUser's docblock for why
        // this is the only enforcement point that reaches both the plain and
        // the two-factor-redirect login paths).
        Fortify::authenticateUsing(new AuthenticateUser);
    }

    /**
     * GitLab #205: the contract-consent gate at login. Fortify's two login
     * responses are the only place both the password and the two-factor
     * path pass through, and neither is a route the `require-consent` alias
     * could be put on.
     *
     * Rebound from `boot()`, not from `registerBindings()`: Fortify's own
     * provider binds these contracts in its `register()`, which runs after
     * every module provider's — a binding made in `register()` here is
     * silently overwritten, and the gate simply never fires.
     *
     * `bind`, not `singleton`: a response object answers one request.
     */
    private function configureFortifyResponses(): void
    {
        $this->app->bind(LoginResponseContract::class, ConsentAwareLoginResponse::class);
        $this->app->bind(TwoFactorLoginResponseContract::class, ConsentAwareTwoFactorLoginResponse::class);
    }

    /**
     * Every auth page carries `operatorPresence` (#184, ADR-0054): whose
     * account this is, and which other brands of the operator it works on.
     * Spread here rather than shared from the Inertia middleware — it costs
     * three queries and exactly one component reads it, so the shop and every
     * member page must not pay for it (fix round 1). `app()` inside a provider
     * closure is this file's own idiom; the closures run per request, and a
     * captured instance would outlive one under Octane.
     */
    private function configureFortifyViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            // The "Sign up" link goes with the gate that would refuse it
            // (EnsureSignupsOpen): a link to a page that bounces back here
            // with an error is worse than no link.
            'canRegister' => Features::enabled(Features::registration()) && app(OperatorSettings::class)->signupsOpen(),
            'status' => $request->session()->get('status'),
            ...app(OperatorPresence::class)->props(),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            ...app(OperatorPresence::class)->props(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ...app(OperatorPresence::class)->props(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            ...app(OperatorPresence::class)->props(),
        ]));

        // One route, two screens (#293): an account still on a placeholder
        // address has nothing a verification link could be sent to, so the
        // notice becomes the form that collects the address. Fortify's
        // controller has already established the account is unverified
        // before this closure runs.
        Fortify::verifyEmailView(function (Request $request) {
            $user = $request->user();

            if ($user instanceof User && $user->hasPlaceholderEmail()) {
                return Inertia::render('auth/SetEmail', [
                    'status' => $request->session()->get('status'),
                    ...app(OperatorPresence::class)->props(),
                ]);
            }

            return Inertia::render('auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
                ...app(OperatorPresence::class)->props(),
            ]);
        });

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword', [
            ...app(OperatorPresence::class)->props(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge', [
            ...app(OperatorPresence::class)->props(),
        ]));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = self::submittedAddress($request, Fortify::username()).'|'.$request->ip();

            return Limit::perMinute(5)->by($throttleKey);
        });

        // #253: named in `config/fortify.php` under `limiters.two-factor`.
        // Keyed on the login in progress, not on the e-mail — by the challenge
        // step the address is no longer in the request; what identifies the
        // attempt is `login.id` in the session (Fortify's
        // RedirectIfTwoFactorAuthenticatable puts it there). Falling back to
        // the session id covers a request that has no challenge at all, so a
        // caller cannot get an unlimited budget by not having one. The IP
        // joins the key so one attacker cannot spend another visitor's.
        //
        // Rotating the session to dodge this costs a fresh login, which the
        // `login` limiter above already caps at five a minute.
        //
        // Five a minute is the same generosity login gets: enough for someone
        // retyping a code off a phone, nowhere near enough to walk a
        // six-digit space or a recovery-code list.
        // The second limit is the one a pool of addresses cannot spend around
        // (review finding 7): with the IP in the key, a thousand sources buy a
        // thousand budgets against the same account, which is a six-digit
        // space in hours and a recovery-code list in far less. `login.id` is
        // the account, so the ceiling can be stated per account too — twenty a
        // minute, well above any human retyping a code and far below a sweep.
        RateLimiter::for('two-factor', function (Request $request): array {
            $attempt = self::challengedAccount($request);

            return [
                Limit::perMinute(5)->by('two-factor|'.$attempt.'|'.($request->ip() ?? 'unknown')),
                Limit::perMinute(20)->by('two-factor|account|'.$attempt),
            ];
        });

        // `POST user/confirm-password` (review finding 5) is the same shape of
        // endpoint one step further in: a password oracle behind nothing but a
        // session, and the gate in front of enabling and disabling two-factor
        // authentication and reading the recovery codes (Fortify's
        // `password.confirm` middleware). Whoever holds a stolen session cookie
        // could try passwords there without limit. Keyed on the authenticated
        // user rather than on anything submitted, with the same per-address
        // budget and account ceiling as the challenge above.
        RateLimiter::for('password-confirmation', function (Request $request): array {
            $account = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by('password-confirmation|'.$account.'|'.($request->ip() ?? 'unknown')),
                Limit::perMinute(20)->by('password-confirmation|account|'.$account),
            ];
        });

        // Registration, forgot-password and reset-password: two limits each,
        // because the two abuses are different shapes. Per IP stops bulk
        // account creation and enumeration sweeps; per submitted address
        // stops one victim's inbox being flooded with reset mails from a
        // rotating set of addresses, which per-IP alone does not.
        //
        // Six a minute is what the rest of this application already uses for
        // the same shape of endpoint: `PUT /settings/password`, the public
        // GDPR endpoints, and Fortify's own e-mail verification.
        $perIpAndAddress = fn (string $name): Closure => function (Request $request) use ($name): array {
            $address = self::submittedAddress($request, 'email');

            return [
                Limit::perMinute(6)->by($name.'|ip|'.($request->ip() ?? 'unknown')),
                Limit::perMinute(6)->by($name.'|address|'.$address),
            ];
        };

        RateLimiter::for('registration', $perIpAndAddress('registration'));
        RateLimiter::for('password-reset', $perIpAndAddress('password-reset'));
    }

    /**
     * The address a limiter keys its per-account budget on, as a string that
     * exists whatever arrived.
     *
     * The `is_string` guard is the point (review finding 6): these closures run
     * before validation, on unauthenticated routes, so `email[]=a&email[]=b`
     * reaches them as an array. Cast straight to string that raises "Array to
     * string conversion", and `HandleExceptions` turns the warning into an
     * `ErrorException` — a 500 on `/login`, `/register`, `/forgot-password` and
     * `/reset-password`, reachable with nothing but a CSRF token. Anything that
     * is not a string shares one bucket, which is the right answer for input
     * that cannot be an address.
     */
    private static function submittedAddress(Request $request, string $key): string
    {
        $raw = $request->input($key);

        return is_string($raw)
            ? Str::transliterate(Str::lower($raw))
            : 'non-string';
    }

    /**
     * The account a two-factor challenge belongs to: `login.id` is what
     * Fortify's `RedirectIfTwoFactorAuthenticatable` writes into the session
     * after the password step. The session id stands in for a request that
     * carries no challenge at all, so a caller cannot buy an unlimited budget
     * by not having one.
     */
    private static function challengedAccount(Request $request): string
    {
        if (! $request->hasSession()) {
            return 'no-session';
        }

        $account = $request->session()->get('login.id');

        return is_scalar($account) ? (string) $account : $request->session()->getId();
    }

    /**
     * Registration, forgot-password and reset-password arrive from Fortify
     * with nothing but `guest:` on them (#253) — user enumeration,
     * reset-mail flooding and bulk account creation, all free. Fortify
     * exposes no configuration hook for these three the way it does for
     * login, verification and the two-factor challenge, so the routes it
     * registered are wrapped after the fact.
     *
     * Done in an `booted()` callback because Fortify's own service provider
     * registers the routes in its `boot()`, and provider boot order is not
     * ours to depend on. `route:cache` boots the application before it
     * serialises the collection, so the added middleware is cached too.
     *
     * The named limiters are defined in `configureRateLimiting()` above; each
     * caps per address of origin and per account. `EnsureCredentialsAreStrings`
     * rides along on the routes whose vendor controller reads the submitted
     * address before validating it (review finding 6).
     */
    private function throttleUnhookedFortifyRoutes(): void
    {
        $credentials = EnsureCredentialsAreStrings::class;
        $signupsOpen = EnsureSignupsOpen::class;

        $additions = [
            // The signup gate on both halves of the form (hotfix
            // 2026-09-13): the page, so a closed instance never shows a
            // form it would refuse, and the submission, so the page being
            // hidden is not the only thing in the way.
            'register' => [$signupsOpen],
            'register.store' => [$signupsOpen, 'throttle:registration', $credentials],
            'password.email' => ['throttle:password-reset', $credentials],
            'password.update' => ['throttle:password-reset', $credentials],
            // Review finding 5: Fortify offers no limiter hook for this one
            // either, and it is a password oracle behind a session.
            'password.confirm.store' => ['throttle:password-confirmation'],
            // Already throttled through `fortify.limiters.login`; it joins the
            // list for the credential guard alone (review finding 6).
            'login.store' => [$credentials],
        ];

        $this->app->booted(function () use ($additions): void {
            // By name, but walked rather than looked up: RouteServiceProvider
            // refreshes the collection's name lookup in a `booted` callback of
            // its own, and ours is registered first — `getByName()` here still
            // answers null for a route that is very much registered.
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $middleware = $additions[$route->getName()] ?? null;

                if ($middleware !== null) {
                    $route->middleware($middleware);
                }
            }
        });
    }

    /**
     * The account itself, and the one handler with a genuinely hard question
     * in it — see the class docblock on why a per-brand request does not
     * anonymize an operator-wide account.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new IdentityGdprHandler);
    }

    /**
     * ADR-0048 point 3/5: `identity.verify-email` and `identity.password-
     * reset` carry no topic — contractual, un-mutable, the suppression list
     * is the only gate — `identity.welcome` and `identity.email-missing`
     * declare one each (`registerMessageTopics()` below).
     */
    protected function registerMessageTemplates(): void
    {
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'identity.verify-email',
            subjects: [
                'de' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse',
                'en' => 'Please confirm your email address',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Bitte bestätigen Sie Ihre E-Mail-Adresse, um Ihr Konto zu
                    aktivieren: <a href="{{ $verifyUrl }}">E-Mail-Adresse bestätigen</a>.</p>
                    <p>Der Link ist {{ $validMinutes }} Minuten gültig.</p>
                    <p>Falls Sie kein Konto angelegt haben, ignorieren Sie diese
                    Nachricht.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Please confirm your email address to activate your account:
                    <a href="{{ $verifyUrl }}">Confirm email address</a>.</p>
                    <p>The link is valid for {{ $validMinutes }} minutes.</p>
                    <p>If you did not create an account, please ignore this
                    message.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: ['verifyUrl' => 'https://example.test/verify/abc123', 'validMinutes' => 60],
        ));

        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'identity.password-reset',
            subjects: [
                'de' => 'Passwort zurücksetzen',
                'en' => 'Reset password',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Sie haben angefordert, Ihr Passwort zurückzusetzen:
                    <a href="{{ $resetUrl }}">Passwort zurücksetzen</a>.</p>
                    <p>Der Link ist {{ $validMinutes }} Minuten gültig.</p>
                    <p>Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie
                    diese Nachricht.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>You requested to reset your password:
                    <a href="{{ $resetUrl }}">Reset password</a>.</p>
                    <p>The link is valid for {{ $validMinutes }} minutes.</p>
                    <p>If you did not request this, please ignore this message.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: ['resetUrl' => 'https://example.test/reset/abc123', 'validMinutes' => 60],
        ));

        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'identity.welcome',
            subjects: [
                'de' => 'Willkommen bei {{ $brandName }}',
                'en' => 'Welcome to {{ $brandName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Willkommen bei {{ $brandName }}!</p>
                    <p>Ihr Konto ist bestätigt.</p>
                    <p>Hier geht es zur Startseite: <a href="{{ $homeUrl }}">{{ $brandName }}</a>.</p>
                    @if ($shopUrl)
                    <p>Im Shop finden Sie unser aktuelles Angebot:
                    <a href="{{ $shopUrl }}">Zum Shop</a>.</p>
                    @endif
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Welcome to {{ $brandName }}!</p>
                    <p>Your account is confirmed.</p>
                    <p>Here is the homepage: <a href="{{ $homeUrl }}">{{ $brandName }}</a>.</p>
                    @if ($shopUrl)
                    <p>Check out our shop for what is on offer:
                    <a href="{{ $shopUrl }}">Go to the shop</a>.</p>
                    @endif
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'brandName' => 'SXLan',
                'homeUrl' => 'https://example.test/',
                'shopUrl' => 'https://example.test/shop',
            ],
        ));

        // #293: the in-app nudge an account on a placeholder address finds in
        // its inbox. In-app only — there is no address to mail — and never
        // suppressible, since the account's ability to be reached at all is
        // what it is about. `InAppChannel` lifts `url` from the payload.
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'identity.email-missing',
            subjects: [
                'de' => 'Bitte E-Mail-Adresse festlegen',
                'en' => 'Please set your email address',
            ],
            // The inbox shows the body as plain text and makes the whole
            // row the link (`notifications/Index.vue`), so no anchor here.
            bodies: [
                'de' => '<p>Ihr Konto wurde ohne E-Mail-Adresse übernommen. Bitte legen Sie eine fest, damit wir Sie erreichen können und Sie Ihr Konto bei Bedarf wiederherstellen können. Bis dahin bleiben Bestellungen und Einstellungen gesperrt.</p>',
                'en' => '<p>Your account was carried over without an email address. Please set one so we can reach you and you can recover your account if you ever need to. Until then, orders and settings stay locked.</p>',
            ],
            defaultLocale: 'de',
            sample: ['url' => 'https://example.test/email/verify'],
        ));
    }

    protected function registerMessageTopics(): void
    {
        $this->app->make(TopicRegistry::class)->register(new Topic(
            key: 'identity.welcome',
            label: 'Welcome and onboarding',
            defaultChannels: [Channel::Mail],
            suppressible: true,
        ));

        $this->app->make(TopicRegistry::class)->register(new Topic(
            key: 'identity.email-missing',
            label: 'Account needs an email address',
            defaultChannels: [Channel::InApp],
            suppressible: false,
        ));
    }
}
