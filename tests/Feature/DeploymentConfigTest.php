<?php

declare(strict_types=1);
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

afterEach(function () {
    unset($_ENV['REVERB_SERVER_PATH'], $_SERVER['REVERB_SERVER_PATH']);
});

/**
 * ADR-0049: deployment switches ride in as env-backed config. These tests
 * pin the keys and their dev-preserving defaults — the chart overrides the
 * env, and a renamed key would silently strand every deployment on the
 * default.
 */
test('inertia ssr is env-switchable with dev-preserving defaults', function () {
    expect(config('inertia.ssr.enabled'))->toBeTrue()
        ->and(config('inertia.ssr.url'))->toBe('http://127.0.0.1:13714');
});

test('backups are scheduled by default and carry the database', function () {
    expect(config('backup.schedule_enabled'))->toBeTrue()
        ->and(config('backup.backup.source.databases'))->toBe(['pgsql']);

    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'backup:run'));

    expect($commands)->toHaveCount(1);
});

test('the disk space check is configurable', function () {
    expect(config('health.disk.enabled'))->toBeTrue()
        ->and(config('health.disk.path'))->toBeNull();

    $checks = collect(Health::registeredChecks())
        ->map(fn ($check): string => $check::class);

    expect($checks)->toContain(UsedDiskSpaceCheck::class);
});

/**
 * ADR-0049 §5: REVERB_SERVER_PATH moves Reverb's *whole* route set, the
 * publish API included, so the broadcasting client has to follow it. What
 * makes this worth a test is that pusher-php-server hands Guzzle
 * `apps/<id>/events` as a relative reference against the configured path:
 * RFC 3986 drops the last segment of a prefix without a trailing slash, so
 * a naive `/ws` resolves back to `/apps/...` and every broadcast 404s while
 * the config looks correct.
 */
test('the reverb publish url follows REVERB_SERVER_PATH', function (?string $path, string $expectedUrl) {
    if ($path === null) {
        unset($_ENV['REVERB_SERVER_PATH'], $_SERVER['REVERB_SERVER_PATH']);
    } else {
        $_ENV['REVERB_SERVER_PATH'] = $path;
        $_SERVER['REVERB_SERVER_PATH'] = $path;
    }

    // Re-evaluated rather than read through config(): the value is baked when
    // the file is loaded, long before this test could set the variable.
    $broadcasting = require config_path('broadcasting.php');
    $options = $broadcasting['connections']['reverb']['options'];

    $baseUri = Utils::uriFor('http://reverb:8080'.$options['path']);
    $resolved = UriResolver::resolve($baseUri, Utils::uriFor('apps/1/events'));

    expect((string) $resolved)->toBe($expectedUrl);
})->with([
    'unset' => [null, 'http://reverb:8080/apps/1/events'],
    'empty' => ['', 'http://reverb:8080/apps/1/events'],
    'prefix' => ['/ws', 'http://reverb:8080/ws/apps/1/events'],
    'prefix with trailing slash' => ['/ws/', 'http://reverb:8080/ws/apps/1/events'],
    'prefix without leading slash' => ['ws', 'http://reverb:8080/ws/apps/1/events'],
]);
