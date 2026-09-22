<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Presence\PresenceWindow;

/**
 * When hub access closes after a scan (D-HUB-01): a once-per-day event
 * closes at the day's end, every other policy stays open until the event
 * concludes. Pure computation on the Admission read model — no rows needed.
 *
 * Helper name prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 *
 * @param  list<array{date: string, label?: string|null, day_ends_at?: string|null}>  $days
 */
function presenceProfile(ReentryPolicy $policy, array $days): EntranceEventProfile
{
    return EntranceEventProfile::factory()->make([
        'reentry_policy' => $policy,
        'days' => array_map(fn (array $d, int $i): array => [
            'day_id' => (string) Str::ulid(), 'sequence' => $i + 1, 'label' => $d['label'] ?? null,
            'date' => $d['date'], 'day_ends_at' => $d['day_ends_at'] ?? null,
        ], $days, array_keys($days)),
    ]);
}

it('opens until the event concludes for unlimited and once-only events', function (ReentryPolicy $policy): void {
    $profile = presenceProfile($policy, [['date' => '2026-10-16', 'label' => 'Freitag']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 22:00', 'Europe/Berlin'));

    expect($window['ends_at'])->toBeNull()->and($window['day_label'])->toBe('Freitag');
})->with([ReentryPolicy::Unlimited, ReentryPolicy::OnceOnly]);

it('ends a once-per-day window at 00:00 of the next local day by default', function (): void {
    $profile = presenceProfile(ReentryPolicy::OncePerDay, [['date' => '2026-10-16']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 22:00', 'Europe/Berlin'));

    expect($window['ends_at']?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-17 00:00');
});

it('treats a day end before noon as the following night', function (): void {
    $profile = presenceProfile(ReentryPolicy::OncePerDay, [['date' => '2026-10-16', 'day_ends_at' => '03:00']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 09:00', 'Europe/Berlin'));

    expect($window['ends_at']?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-17 03:00');
});

it('treats a day end from noon on as the same day', function (): void {
    $profile = presenceProfile(ReentryPolicy::OncePerDay, [['date' => '2026-10-16', 'day_ends_at' => '22:00']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 09:00', 'Europe/Berlin'));

    expect($window['ends_at']?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-16 22:00');
});

it('falls back to the scanned calendar day when no event day covers the scan', function (): void {
    $profile = presenceProfile(ReentryPolicy::OncePerDay, [['date' => '2026-10-20']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 23:30', 'Europe/Berlin'));

    expect($window['ends_at']?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-17 00:00')
        ->and($window['day_label'])->toBeNull();
});

it('returns UTC instants', function (): void {
    $profile = presenceProfile(ReentryPolicy::OncePerDay, [['date' => '2026-10-16']]);
    $window = app(PresenceWindow::class)->for($profile, 'Europe/Berlin', CarbonImmutable::parse('2026-10-16 22:00', 'Europe/Berlin'));

    expect($window['ends_at']?->timezoneName)->toBe('UTC');
});
