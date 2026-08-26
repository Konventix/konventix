<?php

declare(strict_types=1);

namespace Konventix\Identity\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Actions\AuthenticateUser;
use Konventix\Identity\Actions\CreateNewUser;
use Konventix\Identity\Actions\ResetUserPassword;
use Konventix\Identity\Console\ReplayProfilesCommand;
use Konventix\Identity\Enums\Permission;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Listeners\RecordBrandMembership;
use Konventix\Identity\Social\SocialProviderRegistry;
use Konventix\Identity\Social\SteamProvider;
use Konventix\Platform\Cookies\CookieDeclaration;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Enums\CookieCategory;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
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
        $this->configureFortifyViews();
        $this->configureRateLimiting();

        $this->commands([ReplayProfilesCommand::class]);
    }

    /**
     * ADR-0016: registration and login establish the user↔brand membership.
     * `SendEmailVerificationNotification` is Laravel's own default listener —
     * not auto-wired anywhere in this app (no central `EventServiceProvider`,
     * ADR-0003), so `Features::emailVerification()` would otherwise enforce
     * the `verified` middleware against an email nobody ever sent.
     */
    protected function registerListeners(): void
    {
        Event::listen(Login::class, RecordBrandMembership::class);
        Event::listen(Registered::class, RecordBrandMembership::class);
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        // Community Socialite providers are not auto-discovered the way the
        // built-in ones are — they extend the manager by listening for this
        // event. Registered explicitly here because modular event discovery is
        // off and stays off (.ai/rules/listeners.md); without this line
        // `Socialite::driver('steam')` raises "Driver [steam] not supported".
        Event::listen(SocialiteWasCalled::class, [SteamExtendSocialite::class, 'handle']);
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

    private function configureFortifyViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
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
}
