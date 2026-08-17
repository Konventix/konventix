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
use Konventix\Identity\Enums\Permission;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Listeners\RecordBrandMembership;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

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
