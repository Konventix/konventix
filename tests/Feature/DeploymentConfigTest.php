<?php

declare(strict_types=1);
use Illuminate\Console\Scheduling\Schedule;

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
