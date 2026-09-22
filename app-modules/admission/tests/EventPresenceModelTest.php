<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Models\EventPresence;

it('keeps one presence row per user and event', function (): void {
    $this->withBrand();
    $row = EventPresence::factory()->create();

    expect(fn () => EventPresence::factory()->create([
        'event_id' => $row->event_id,
        'user_id' => $row->user_id,
    ]))->toThrow(QueryException::class);
});

it('casts the end reason and the instants', function (): void {
    $this->withBrand();
    $row = EventPresence::factory()->create([
        'hub_access' => false,
        'access_ended_reason' => HubAccessEndReason::DayEnded,
    ]);

    expect($row->fresh()->access_ended_reason)->toBe(HubAccessEndReason::DayEnded)
        ->and($row->fresh()->first_admitted_at)->toBeInstanceOf(CarbonImmutable::class);
});
