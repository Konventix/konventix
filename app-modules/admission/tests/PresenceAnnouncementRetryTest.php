<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Platform\Models\HubAccess;

/**
 * The projection is written from a queued job (`tries: 3`), and the
 * handlers behind the participant events are not queued themselves — so
 * they run inline, inside that job, and one of them throwing fails the
 * whole job *after* the presence row was saved.
 *
 * If the transition were read off the saved row, the retry would see the
 * new state, conclude that nothing changed, and swallow the event for
 * good: a participant admitted at the door with no live pill, or one
 * still holding the hub after their admission was revoked, in both cases
 * silently. `announced_hub_access` is what makes the retry re-emit, and
 * these two tests are the pin — one per direction.
 *
 * The failing listener is registered after Platform's, which is the real
 * shape of it: Platform's own projection has already run when a later
 * handler throws, so the assertion is that a redelivery leaves that row
 * right rather than that it was never written.
 */
beforeEach(function (): void {
    $this->brand = $this->withBrand();
    $this->eventId = (string) Str::ulid();
    ticketEventSummary($this->brand, $this->eventId);
    EntranceEventProfile::factory()->create(['event_id' => $this->eventId]);
    $this->holder = ticketPerson();
    $this->admission = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => $this->holder->id]);
    $this->scannedAt = CarbonImmutable::parse('2026-10-16T20:00:00+02:00');
});

/**
 * Registers a listener that throws the first time it is handed the event —
 * or every time, with `$alwaysThrows`, which is the job that exhausts its
 * retries — and counts the deliveries that got through afterwards.
 *
 * @param  class-string  $event
 * @return object{delivered: int, survived: int}
 */
function presenceAnnouncementTrap(string $event, bool $alwaysThrows = false): object
{
    $counters = new class
    {
        public int $delivered = 0;

        public int $survived = 0;
    };

    EventBus::listen($event, function () use ($counters, $alwaysThrows): void {
        $counters->delivered++;

        if ($alwaysThrows || $counters->delivered === 1) {
            throw new RuntimeException('the handler behind this event blew up');
        }

        $counters->survived++;
    });

    return $counters;
}

it('re-emits ParticipantAdmitted on the retry and exactly once', function (): void {
    $trap = presenceAnnouncementTrap(ParticipantAdmitted::class);
    $action = app(ProjectEventPresence::class);

    $attempt = fn (): ?EventPresence => $action->checkIn($this->brand->id, $this->eventId, $this->admission->id, 'in', $this->scannedAt);

    expect($attempt)->toThrow(RuntimeException::class);

    // The row is admitted, but nothing has acknowledged the announcement:
    // reading the transition off `hub_access` here is the bug.
    $row = EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail();
    expect($row->hub_access)->toBeTrue()
        ->and($row->announced_hub_access)->toBeNull();

    $attempt();

    expect($trap->delivered)->toBe(2)
        ->and($trap->survived)->toBe(1)
        ->and(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail()->announced_hub_access)->toBeTrue();

    // A later scan on the same open window announces nothing further.
    $attempt();
    expect($trap->delivered)->toBe(2);

    $access = HubAccess::withoutBrandScope()->where('user_id', $this->holder->id)->get();
    expect($access)->toHaveCount(1)
        ->and($access->first()?->admission_id)->toBe($this->admission->id)
        ->and($access->first()?->event_id)->toBe($this->eventId);
});

it('re-emits ParticipantHubAccessEnded on the retry and exactly once', function (): void {
    event(presenceScan($this->admission));
    expect(HubAccess::withoutBrandScope()->where('user_id', $this->holder->id)->count())->toBe(1);

    $trap = presenceAnnouncementTrap(ParticipantHubAccessEnded::class);
    $action = app(ProjectEventPresence::class);

    $attempt = fn (): int => $action->endForEvent($this->brand->id, $this->eventId, HubAccessEndReason::Concluded, $this->scannedAt);

    expect($attempt)->toThrow(RuntimeException::class);

    // Access is gone from the row, so the "still has access" filter alone
    // would never look at it again.
    $row = EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail();
    expect($row->hub_access)->toBeFalse()
        ->and($row->announced_hub_access)->toBeTrue();

    expect($attempt())->toBe(0); // the access was already lost on the first pass

    expect($trap->delivered)->toBe(2)
        ->and($trap->survived)->toBe(1)
        ->and(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail()->announced_hub_access)->toBeFalse()
        ->and(HubAccess::withoutBrandScope()->where('user_id', $this->holder->id)->count())->toBe(0);

    // A settled row is done with: a second sweep says nothing.
    expect($attempt())->toBe(0);
    expect($trap->delivered)->toBe(2);
});

it('re-emits ParticipantPresenceChanged on the retry so the on-site badge cannot stick', function (): void {
    event(presenceScan($this->admission));

    $trap = presenceAnnouncementTrap(ParticipantPresenceChanged::class);
    $action = app(ProjectEventPresence::class);

    $attempt = fn (): ?EventPresence => $action->checkIn($this->brand->id, $this->eventId, $this->admission->id, 'out', $this->scannedAt->addHour());

    expect($attempt)->toThrow(RuntimeException::class);
    expect(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail())
        ->last_direction->toBe('out')
        ->announced_direction->toBe('in');

    $attempt();

    expect($trap->survived)->toBe(1)
        ->and(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail()->announced_direction)->toBe('out')
        ->and(HubAccess::withoutBrandScope()->where('user_id', $this->holder->id)->firstOrFail()->on_site)->toBeFalse();

    $attempt();
    expect($trap->delivered)->toBe(2);
});

/**
 * The third state, and the one that produces the inverse symptom: a pill
 * that will not go away.
 *
 * A freshly inserted row carries `announced_hub_access = null`. If the
 * admitted announcement exhausts its retries, the marker stays null — and
 * when the event then concludes, `endRow()` flips `hub_access` and emits,
 * and if *that* dispatch dies too the row is `(false, null)`. A filter of
 * "still has access, or lost it having been announced" matches neither
 * term, so nothing would ever look at the row again: Platform keeps its
 * `platform_hub_access` row and the participant keeps the pill and `/live`
 * after the event is over. `admission:replay-presence` cannot repair it
 * either — it replays rows that still have access.
 */
it('still finishes a row whose admitted announcement never got out either', function (): void {
    // The admitted announcement fails for good: three attempts, all thrown.
    $admitted = presenceAnnouncementTrap(ParticipantAdmitted::class, alwaysThrows: true);
    $action = app(ProjectEventPresence::class);

    foreach (range(1, 3) as $ignored) {
        expect(fn () => $action->checkIn($this->brand->id, $this->eventId, $this->admission->id, 'in', $this->scannedAt))
            ->toThrow(RuntimeException::class);
    }

    expect($admitted->survived)->toBe(0)
        ->and(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail())
        ->hub_access->toBeTrue()
        ->announced_hub_access->toBeNull();

    // The event concludes, and that announcement dies once as well.
    $ended = presenceAnnouncementTrap(ParticipantHubAccessEnded::class);
    $sweep = fn (): int => $action->endForEvent($this->brand->id, $this->eventId, HubAccessEndReason::Concluded, $this->scannedAt);

    expect($sweep)->toThrow(RuntimeException::class);

    $row = EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail();
    expect($row->hub_access)->toBeFalse()
        ->and($row->announced_hub_access)->toBeNull();

    // Neither "has access" nor "was announced gone" — and it still has to
    // be picked up, or the pill never goes away.
    $sweep();

    expect($ended->survived)->toBe(1)
        ->and(EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail()->announced_hub_access)->toBeFalse();

    // Settled now: a further sweep says nothing.
    $sweep();
    expect($ended->delivered)->toBe(2);
});
