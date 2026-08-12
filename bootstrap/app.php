<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Http\Middleware\ResolveBrand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
