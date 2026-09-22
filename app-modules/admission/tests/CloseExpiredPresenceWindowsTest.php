<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;

it('closes windows that have passed and leaves open-ended rows alone', function (): void {
    $brand = $this->withBrand();
    $daily = (string) Str::ulid();
    $open = (string) Str::ulid();
    ticketEventSummary($brand, $daily, ['timezone' => 'Europe/Berlin']);
    ticketEventSummary($brand, $open, ['slug' => 'open-fest']);
    EntranceEventProfile::factory()->create(['event_id' => $daily, 'reentry_policy' => ReentryPolicy::OncePerDay,
        'days' => [['day_id' => (string) Str::ulid(), 'date' => '2026-10-16', 'sequence' => 1, 'label' => null, 'day_ends_at' => null]]]);
    EntranceEventProfile::factory()->create(['event_id' => $open]);

    event(presenceScan(Admission::factory()->create(['event_id' => $daily, 'holder_user_id' => ticketPerson()->id])));
    event(presenceScan(Admission::factory()->create(['event_id' => $open, 'holder_user_id' => ticketPerson()->id])));

    CarbonImmutable::setTestNow('2026-10-17T00:01:00+02:00');
    EventBus::fake([ParticipantHubAccessEnded::class]);

    Artisan::call('admission:close-presence-windows');

    expect(EventPresence::query()->where('event_id', $daily)->firstOrFail()->access_ended_reason)->toBe(HubAccessEndReason::DayEnded)
        ->and(EventPresence::query()->where('event_id', $open)->firstOrFail()->hub_access)->toBeTrue();
    EventBus::assertDispatchedTimes(ParticipantHubAccessEnded::class, 1);

    CarbonImmutable::setTestNow();
});

it('is scheduled every minute without overlapping', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e): bool => str_contains($e->command ?? '', 'admission:close-presence-windows'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
