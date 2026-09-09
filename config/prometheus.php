<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyHealthSecret;
use Spatie\Prometheus\Actions\RenderCollectorsAction;

/*
 * spatie/laravel-prometheus, plus this application's own keys at the end.
 * The package renders gauges at scrape time; counters and histograms go
 * through Konventix\Platform\Observability\Metrics and the shared
 * Redis-backed registry bound in ObservabilityServiceProvider (ADR-0059).
 */
return [
    'enabled' => true,

    'urls' => [
        'default' => 'metrics',
    ],

    // Unused: the endpoint is guarded by the shared secret, not by address.
    'allowed_ips' => [],

    'default_namespace' => 'konventix',

    'middleware' => [
        VerifyHealthSecret::class,
    ],

    'actions' => [
        'render_collectors' => RenderCollectorsAction::class,
    ],

    'wipe_storage_after_rendering' => false,

    // The package's own cache adapter is not used; see `storage` below.
    'cache' => null,

    /*
     * `redis` shares one registry across web, horizon, scheduler and pulse
     * through the `redis_connection` below. `memory` keeps it in the process,
     * which is what the test suite and a one-off artisan run want.
     */
    'storage' => env('PROMETHEUS_STORAGE', 'redis'),

    // The dedicated `metrics` connection (config/database.php), not
    // `default`: it carries no phpredis OPT_PREFIX, which promphp's RedisNg
    // adapter cannot see through when it collects (ADR-0059).
    'redis_connection' => 'metrics',

    'http' => [
        /*
         * Routes that get their own `route` label on the request duration
         * histogram (#148 names thresholds for these pages). Everything else
         * is observed under `other`. Keep this list short: every entry is a
         * dozen series in Redis and a write per request.
         */
        'routes' => [
            'home',
            'event.index',
            'event.show',
            'commerce.shop.index',
            'commerce.shop.show',
            'commerce.cart.show',
            'commerce.cart.checkout',
            'commerce.pay.order.start',
            'commerce.pay.order.show',
            'commerce.waiting-room.show',
            'commerce.waiting-room.status',
            'login',
            'registration.store',
        ],
        'route_prefixes' => [
            'api.commerce.webhooks.',
        ],
        'buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
    ],
];
