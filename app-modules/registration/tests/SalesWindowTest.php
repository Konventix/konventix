<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Registration\Models\RegistrationType;

/**
 * #218 (#216, draft i): a registration type declares when it is buyable.
 *
 * `sales_start`/`sales_end` are real instants, not wall-clock times: ADR-0027
 * covers the event's own schedule ("doors open 10:00"), while a sales window
 * is a deadline of the same shape as `carts.expires_at` — and this module
 * holds no timezone for the event at all (`registration_event_summaries`
 * carries name, slug and `archived_at` only, ADR-0004).
 *
 * Both columns are nullable and null means "no bound": a type with neither
 * behaves exactly as every type did before this existed.
 */
it('is on sale when it declares no window at all', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create();

    expect($type->sales_start)->toBeNull()
        ->and($type->sales_end)->toBeNull()
        ->and($type->isOnSale())->toBeTrue();
});

it('is not on sale before its start and is on sale from the start onwards', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(start: '2026-10-01 18:00:00')->create();

    expect($type->isOnSale(Carbon::parse('2026-10-01 17:59:59Z')))->toBeFalse()
        ->and($type->isOnSale(Carbon::parse('2026-10-01 18:00:00Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-12-24 12:00:00Z')))->toBeTrue();
});

it('is not on sale once its end has passed', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(end: '2026-10-08 18:00:00')->create();

    expect($type->isOnSale(Carbon::parse('2026-10-08 17:59:59Z')))->toBeTrue()
        ->and($type->isOnSale(Carbon::parse('2026-10-08 18:00:00Z')))->toBeFalse();
});

it('defaults to the current time when no clock is passed', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->salesWindow(start: '2026-10-01 18:00:00')->create();

    $this->travelTo(Carbon::parse('2026-09-30 12:00:00Z'));
    expect($type->isOnSale())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-02 12:00:00Z'));
    expect($type->isOnSale())->toBeTrue();
});

it('round-trips both columns as instants', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->salesWindow(start: '2026-10-01 18:00:00', end: '2026-10-08 18:00:00')
        ->create();

    expect($type->fresh()->sales_start?->toIso8601String())->toBe('2026-10-01T18:00:00+00:00')
        ->and($type->fresh()->sales_end?->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');
});
