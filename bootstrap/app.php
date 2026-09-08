<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyHealthSecret;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Http\Middleware\ResolveBrand;
use Sentry\Laravel\Integration;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Registered here rather than in routes/web.php because that file
            // is loaded with the `web` group, which ResolveBrand fronts — every
            // route in it 404s on a host that is not a brand or the console.
            // An external monitor reaches this application on whatever internal
            // address the proxy uses, so this endpoint has to survive exactly
            // that, the same way Laravel's own `/up` does.
            //
            // The secret is checked in middleware, and answers 404 when unset.
            Route::get('health-json', HealthCheckJsonResultsController::class)
                ->middleware(VerifyHealthSecret::class)
                ->name('health.json');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request gets its correlation ID before anything else runs,
        // so the very first log line of a request already carries it
        // (ADR-0056). Global rather than `web`: health probes, PSP webhooks
        // and the router's own 404s are requests too.
        $middleware->prepend(AssignCorrelationId::class);

        // Security headers on every PHP response, for the same reason the
        // correlation ID is global (#251): the surfaces that need them most —
        // the unauthenticated media route (#252), the PSP webhooks, the
        // health endpoints — are the ones outside the `web` group.
        //
        // Appended, not prepended, and the ordering is load-bearing:
        // `TrustProxies` sits in the global stack below and has to run FIRST,
        // or `$request->isSecure()` reads the raw connection instead of
        // `X-Forwarded-Proto` and HSTS never goes out behind the ingress. The
        // price of appending is that a response produced before this
        // middleware — `ValidatePostSize`'s 413, a `TrustProxies` failure —
        // carries no headers; a rendered exception does, because the router's
        // pipeline renders inside it.
        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'cookie_preferences']);

        // TLS terminates at a reverse proxy that is not part of this
        // application, so the forwarded headers are the only truth about what
        // the visitor actually asked for. `X-Forwarded-Host` matters most:
        // brands are resolved from the request host (ADR-0001), so without it
        // every request resolves the proxy's own hostname — the wrong brand,
        // or a 404. `X-Forwarded-Proto` is what makes `isSecure()` true, and
        // therefore what makes secure session cookies, correctly signed URLs
        // (Admission's entrance tokens, Compliance's export downloads) and
        // ADR-0025's per-brand Reverb origins agree with reality.
        //
        // Which proxies to believe is config, not a literal — see
        // config/trustedproxy.php for why it cannot be an env() call here.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(prepend: [
            // Every web request runs in a brand or 404s (ADR-0001). Wired here
            // rather than in PlatformServiceProvider because the kernel syncs
            // group definitions to the router after providers boot, which
            // would silently drop a boot-time prepend.
            ResolveBrand::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Admin routes 404 on brand hosts BEFORE auth gets to redirect —
        // their existence is nobody's business there (ADR-0029). The priority
        // list names the AuthenticatesRequests CONTRACT, not the concrete
        // Authenticate class — targeting the class appends after it instead.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureOperatorHost::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry reports exceptions with their stack, release and user, so a
        // failure an organiser mentions in passing is findable rather than
        // reproducible-if-lucky. Inert until `SENTRY_LARAVEL_DSN` is set, so
        // development and CI send nothing anywhere.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A failed validation flashes the request body into the session as
        // `_old_input` so the form can repopulate. `config/session.php` leaves
        // `SESSION_ENCRYPT` off, so whatever lands there sits in the session
        // store in cleartext — and the settlement console posts PSP secrets
        // through ordinary form fields: Stripe's pair for the whole instance
        // (ADR-0058 point 2, camelCase because it arrives as a Data object) and
        // Mollie's and Unzer's per-brand keys (ADR-0039 point 4). A mistyped
        // signing secret beside a perfectly good `sk_live_` must not leave the
        // good one behind. Laravel's own default (`password`,
        // `password_confirmation`, `current_password`) is restated because
        // naming this list replaces it rather than extending it.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'secretKey',
            'secret_key',
            'webhookSecret',
            'webhook_secret',
            'publishableKey',
            'publishable_key',
            'api_key',
            'private_key',
        ]);
    })->create();
