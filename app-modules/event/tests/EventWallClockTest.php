<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;

/**
 * ADR-0027: events store wall-clock local time plus an IANA timezone, not a
 * UTC instant. The raw column must read back exactly what was written
 * regardless of timezone, and only the dedicated accessors may convert it to
 * a real instant.
 */
beforeEach(function (): void {
    $this->withBrand();
});

it('keeps the stored wall-clock time unchanged across a read, regardless of timezone', function (): void {
    $event = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Pacific/Auckland',
    ]);

    $fresh = $event->fresh();

    expect($fresh->starts_at->format('H:i'))->toBe('10:00')
        ->and($fresh->ends_at->format('H:i'))->toBe('18:00');
});

it('converts the wall-clock start time to a real instant through the event timezone', function (): void {
    $event = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        // CEST (UTC+2) in June.
        'timezone' => 'Europe/Berlin',
    ]);

    $instant = $event->startsAtInstant();

    expect($instant->format('H:i'))->toBe('10:00')
        ->and($instant->copy()->utc()->format('H:i'))->toBe('08:00');
});

it('converts the wall-clock end time to a real instant through the event timezone', function (): void {
    $event = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    $instant = $event->endsAtInstant();

    expect($instant->format('H:i'))->toBe('18:00')
        ->and($instant->copy()->utc()->format('H:i'))->toBe('16:00');
});

it('produces a different real instant for the same wall-clock time in a different timezone', function (): void {
    $berlin = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    $auckland = Event::factory()->create([
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Pacific/Auckland',
    ]);

    expect($berlin->startsAtInstant()->copy()->utc()->eq($auckland->startsAtInstant()->copy()->utc()))
        ->toBeFalse();
});
