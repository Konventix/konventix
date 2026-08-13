<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Event\Enums\ReentryPolicy as EventReentryPolicy;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventReentryPolicyChanged;

/**
 * Admission's read model of the two Event facts the door needs offline
 * (ADR-0033). Admission may not read Event's models (ADR-0004), so this
 * projection is the only path by which the day calendar and the re-entry
 * policy reach the entrance API at all.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 *
 * @return list<array{day_id: string, date: string, sequence: int, label: string|null}>
 */
function profileTestDays(int $count): array
{
    $days = [];

    for ($i = 0; $i < $count; $i++) {
        $days[] = [
            'day_id' => (string) Str::ulid(),
            'date' => now()->addDays($i)->format('Y-m-d'),
            'sequence' => $i + 1,
            'label' => 'Day '.($i + 1),
        ];
    }

    return $days;
}

it('projects the day calendar from EventDaysChanged', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $days = profileTestDays(3);

    event(new EventDaysChanged(eventId: $eventId, brandId: $brand->id, days: $days));

    $profile = EntranceEventProfile::query()->where('event_id', $eventId)->firstOrFail();

    expect($profile->days)->toHaveCount(3)
        // Defaults until the policy event arrives — and unlimited is the safe
        // reading, since a door that locks everyone out is the worse failure.
        ->and($profile->reentry_policy)->toBe(ReentryPolicy::Unlimited);
});

it('replaces the calendar rather than patching it', function (): void {
    // Both payloads carry the complete current set, which is what makes a
    // replay converge instead of accumulating stale days.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventDaysChanged(eventId: $eventId, brandId: $brand->id, days: profileTestDays(3)));
    event(new EventDaysChanged(eventId: $eventId, brandId: $brand->id, days: profileTestDays(1)));

    expect(EntranceEventProfile::query()->where('event_id', $eventId)->firstOrFail()->days)
        ->toHaveCount(1);
});

it('projects the re-entry policy from EventReentryPolicyChanged', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventReentryPolicyChanged(
        eventId: $eventId,
        brandId: $brand->id,
        reentryPolicy: EventReentryPolicy::OncePerDay->value,
    ));

    expect(EntranceEventProfile::query()->where('event_id', $eventId)->firstOrFail()->reentry_policy)
        ->toBe(ReentryPolicy::OncePerDay);
});

it('keeps one profile per event as both projections arrive', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventDaysChanged(eventId: $eventId, brandId: $brand->id, days: profileTestDays(2)));
    event(new EventReentryPolicyChanged(
        eventId: $eventId,
        brandId: $brand->id,
        reentryPolicy: EventReentryPolicy::OnceOnly->value,
    ));

    $profiles = EntranceEventProfile::query()->where('event_id', $eventId)->get();

    expect($profiles)->toHaveCount(1)
        ->and($profiles->first()->days)->toHaveCount(2)
        ->and($profiles->first()->reentry_policy)->toBe(ReentryPolicy::OnceOnly);
});

it('falls back to unlimited rather than locking the door on an unknown policy value', function (): void {
    // If Event ever grows a case this module has not learned, ADR-0033's
    // "never degrades to a closed door" applies to the projection too.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventReentryPolicyChanged(
        eventId: $eventId,
        brandId: $brand->id,
        reentryPolicy: 'once_per_hour_on_tuesdays',
    ));

    expect(EntranceEventProfile::query()->where('event_id', $eventId)->firstOrFail()->reentry_policy)
        ->toBe(ReentryPolicy::Unlimited);
});

it('resolves the event day covering a moment, and none outside the calendar', function (): void {
    $this->withBrand();

    $days = profileTestDays(2);
    $profile = EntranceEventProfile::factory()->withDays($days)->create();

    expect($profile->dayCovering(now())['day_id'])->toBe($days[0]['day_id'])
        ->and($profile->dayCovering(now()->addDay())['day_id'])->toBe($days[1]['day_id'])
        ->and($profile->dayCovering(now()->addWeek()))->toBeNull();
});

it('carries the ADR-0033 sync cadence with per-event overrides', function (): void {
    $this->withBrand();

    $default = EntranceEventProfile::factory()->create();

    expect($default->pollSeconds())->toBe(30)
        ->and($default->staleAfterSeconds())->toBe(900);

    $tightened = EntranceEventProfile::factory()->create([
        'poll_seconds' => 10,
        'stale_after_seconds' => 300,
    ]);

    expect($tightened->pollSeconds())->toBe(10)
        ->and($tightened->staleAfterSeconds())->toBe(300);
});
