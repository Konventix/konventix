<?php

declare(strict_types=1);

namespace Konventix\Agenda\Listeners;

use Konventix\Agenda\Models\EventSummary;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\EventUnpublished;

/**
 * Keeps Agenda's copy of an event's slug, name, timezone, day set and
 * lifecycle state (ADR-0004, design §3) — mirrors
 * `Konventix\Admission\Listeners\ProjectEventSummary`, which is the shape
 * this module copied.
 *
 * Every `Konventix\Event\Events\*` import is the one sanctioned
 * cross-module dependency; everything past this line sees scalars only.
 *
 * The four events carry different halves of the row, so all four call the
 * same `project()` with only what their own payload holds — its
 * `array_filter` drops the rest, and neither call erases what another set.
 * `handleRescheduled()` and `handleDaysChanged()` may also *create* the
 * row, because a replay backfill can reach this module in any order and a
 * day set with no row to hang on would simply be lost.
 *
 * Deliberately **not** short-circuited on the Pennant gate, the same
 * reasoning `Konventix\Resources\Listeners\ProjectEventWindow` gives: a
 * brand that switches Agenda on halfway through planning must find its
 * events already here, or the programme editor would 404 on every one of
 * them.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            slug: $event->slug,
            name: $event->name,
            timezone: $event->timezone,
            // `CreateEvent` is the only emitter and it always creates a
            // draft, so the state is the event's meaning rather than a
            // field it carries.
            state: 'draft',
        );
    }

    /**
     * Every lifecycle transition Event announces, in one handler: each of
     * these events *is* a state rather than carrying one, so the class is
     * the payload. Registered seven times in
     * `AgendaServiceProvider::registerListeners()`.
     *
     * Unpublishing returns an event to `Announced`, which is
     * `UnpublishEvent`'s own target state — not to `Draft`, and certainly
     * not to nothing.
     *
     * This is what keeps a draft event's programme off the public web
     * (`EventSummary::hasPublicProgramme()`); the editor is deliberately
     * not gated on it.
     */
    public function handleLifecycle(
        EventAnnounced|EventArchived|EventCancelled|EventConcluded|EventPublished|EventStarted|EventUnpublished $event,
    ): void {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            state: match ($event::class) {
                EventAnnounced::class, EventUnpublished::class => 'announced',
                EventPublished::class => 'published',
                EventStarted::class => 'running',
                EventConcluded::class => 'concluded',
                EventCancelled::class => 'cancelled',
                EventArchived::class => 'archived',
            },
        );
    }

    /**
     * `EventDetailsUpdated` carries no zone and no days: slug and name only.
     */
    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            slug: $event->slug,
            name: $event->name,
        );
    }

    /**
     * The opposite gap: times and a zone, no slug and no name. Only the
     * zone matters here — the programme's own hours come from its items,
     * not from the event's window — but the zone is what every wall clock
     * on the page is read in, so a re-zoned event has to reach this row.
     */
    public function handleRescheduled(EventRescheduled $event): void
    {
        if ($event->brandId === null) {
            return;
        }

        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            timezone: $event->timezone,
        );
    }

    /**
     * Always the complete current set (the event's own docblock), so this
     * replaces rather than patches — including with an empty list, which is
     * why `days` is compared against null and not against emptiness below.
     */
    public function handleDaysChanged(EventDaysChanged $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            days: self::programmeDays($event->days),
        );
    }

    /**
     * The payload's days in `sequence` order and stripped to the three
     * fields `ProgrammeBuilder::forEvent()` reads. `day_ends_at` is the
     * live hub's (D-HUB-01) and says nothing about a programme row.
     *
     * @param  list<array{day_id: string, date: string, sequence: int, label: string|null, day_ends_at?: string|null}>  $days
     * @return list<array{day_id: string, date: string, label: string|null}>
     */
    private static function programmeDays(array $days): array
    {
        usort($days, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return array_map(static fn (array $day): array => [
            'day_id' => $day['day_id'],
            'date' => $day['date'],
            'label' => $day['label'] ?? null,
        ], $days);
    }

    /**
     * `withoutBrandScope()` and an explicit `brand_id` throughout: a
     * contract event is consumed wherever it was fired — a console
     * command, a queue worker, a request on the operator admin host where
     * the scope hardens to zero rows (ADR-0029) — so the brand comes from
     * the payload and never from ambient context (ADR-0010).
     *
     * @param  list<array{day_id: string, date: string, label: string|null}>|null  $days
     */
    private function project(
        string $eventId,
        string $brandId,
        ?string $slug = null,
        ?string $name = null,
        ?string $timezone = null,
        ?string $state = null,
        ?array $days = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'slug' => $slug,
            'name' => $name,
            'timezone' => $timezone,
            'state' => $state,
            'days' => $days,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }
}
