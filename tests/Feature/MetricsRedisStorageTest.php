<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;
use Konventix\Platform\Observability\Metrics;
use Prometheus\CollectorRegistry;

/**
 * The rest of the suite runs with `PROMETHEUS_STORAGE=memory` (phpunit.xml),
 * so this is the one place the Redis-backed registry actually renders
 * (ADR-0059, GitLab #173). It proves the fix directly: the dedicated
 * `metrics` Redis connection (config/database.php) carries no phpredis
 * `OPT_PREFIX` even while `database.redis.options.prefix` is set to
 * something else for every other connection — promphp's RedisNg adapter
 * strips a client-side prefix with `ltrim()` (a character mask, not a
 * substring) when it collects, so an `OPT_PREFIX` on that connection makes
 * `/metrics` render garbage or nothing at all. It also overrides
 * `prometheus.redis_prefix` to a test-only value: this test and a running
 * instance (Horizon, scheduler, web) share the same Redis, and the
 * `afterEach` wipe below must never reach the real `konventix:metrics:`
 * prefix a live dashboard reads from.
 */
afterEach(function (): void {
    // Scoped to this test's own prefix (set below), never the real
    // 'konventix:metrics:' one a running instance's Horizon, scheduler and
    // web roles write into — wiping that would zero a live dashboard.
    if (config('prometheus.storage') === 'redis') {
        app(CollectorRegistry::class)->wipeStorage();
    }
});

it('renders through the redis-backed registry on a connection carrying no key prefix', function (): void {
    // A prefix on every other connection, so a passing assertion here
    // cannot be an accident of both connections happening to be unprefixed.
    // A distinct metrics key prefix too, so this test's own wipeStorage()
    // (afterEach above) cannot reach the real 'konventix:metrics:' prefix a
    // live instance sharing this Redis is writing into.
    config([
        'prometheus.storage' => 'redis',
        'prometheus.redis_prefix' => 'konventix:metrics-test:',
        'database.redis.options.prefix' => 'prefix-probe-',
    ]);

    // The provider's CollectorRegistry binding is `scoped`, so rebuild it
    // (and clear the cached instance) to pick up the redis storage just
    // configured above.
    app()->forgetInstance(CollectorRegistry::class);
    app()->forgetScopedInstances();

    expect(Redis::connection('metrics')->client()->getOption(\Redis::OPT_PREFIX))->toBeEmpty()
        ->and(Redis::connection('default')->client()->getOption(\Redis::OPT_PREFIX))->toBe('prefix-probe-');

    $client = Redis::connection('metrics')->client();
    $liveKeysBefore = count($client->keys('konventix:metrics:*'));

    app(MetricCatalog::class)->register(
        new MetricDefinition('redis_storage_probe_total', MetricKind::Counter, 'Proves the redis-backed registry renders (#173).'),
    );

    app(Metrics::class)->increment('redis_storage_probe_total');

    // The write landed under this test's own prefix, and touched nothing
    // under the real one a live instance uses.
    expect($client->keys('konventix:metrics-test:*'))->not->toBeEmpty()
        ->and(count($client->keys('konventix:metrics:*')))->toBe($liveKeysBefore);

    config()->set('health.endpoint_secret', 'metrics-test-secret');

    $this->withHeader('Authorization', 'Bearer metrics-test-secret')
        ->get('/metrics')
        ->assertOk()
        ->assertSee('konventix_redis_storage_probe_total 1', escape: false);
});
