<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Agenda knows it (ADR-0004: this module may not read Event's
 * models, so it projects what it needs from Event's contract events) —
 * design §3, the same shape `Konventix\Admission\Models\EventSummary` and
 * `resource_event_windows` already have.
 *
 * Five facts, and only those the programme actually needs: the `slug` the
 * public route resolves by, the `name` the page is headed with, the
 * `timezone` every wall clock on it is read in (ADR-0027), the `days`
 * the switcher is built from — which is also `ProgrammeBuilder::forEvent()`'s
 * `$days` argument, stored in the shape that method wants it — and the
 * `state` the event's own lifecycle is in, which is what keeps a draft
 * event's programme off the public web.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated`/`EventDaysChanged` rather than inventing one.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $timezone
 * @property string|null $state
 * @property array<int, mixed>|null $days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'agenda_event_summaries';

    protected $fillable = ['event_id', 'slug', 'name', 'timezone', 'state', 'days'];

    /**
     * The lifecycle states in which an event's programme is a public
     * announcement. `EventLifecycleState`'s backing values, spelled as
     * strings because Agenda may import Event's `Events\` namespace and
     * nothing else (ADR-0004).
     *
     * `draft` and `announced` are the organizer's workbench: the programme
     * is being built, and a page reachable by guessing a slug would publish
     * next year's half-finished schedule by accident. `cancelled` and
     * `archived` are over — the event's own landing page is the place that
     * says so, not a timetable for something that is not happening.
     * `concluded` stays up, because a programme is also a record of what
     * happened.
     */
    private const PUBLIC_STATES = ['published', 'running', 'concluded'];

    /**
     * Whether the public programme page resolves for this event.
     *
     * A `null` state is "nobody has told us yet", and the answer to that is
     * no — refusing is the safe default when we cannot tell, the same call
     * `ModuleGate::isActive()` makes for a missing brand. It is reachable:
     * `event:replay-details` re-emits neither `EventCreated` nor any
     * lifecycle event, so an event backfilled into this read model has no
     * state until its next transition. That is a gap in the backfill, not a
     * reason to open the page.
     *
     * The editor deliberately does not ask this question — a draft event is
     * exactly when a programme gets built.
     */
    public function hasPublicProgramme(): bool
    {
        return in_array($this->state, self::PUBLIC_STATES, true);
    }

    /**
     * The event's own zone, or the application's while Event has not said
     * one yet. Never the reader's: a programme is promised in the
     * organizer's clock (ADR-0027).
     */
    public function eventTimezone(): string
    {
        return $this->timezone ?? (string) config('app.timezone');
    }

    /**
     * The day set as `ProgrammeBuilder::forEvent()` takes it.
     *
     * Read defensively rather than trusted: the column is JSON written by a
     * projection that has been replayed and reshaped before, and a single
     * malformed row must cost one day of the switcher, not the whole page.
     *
     * @return list<array{day_id: string, date: string, label: string|null}>
     */
    public function programmeDays(): array
    {
        $days = [];

        foreach ($this->days ?? [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            $dayId = $day['day_id'] ?? null;
            $date = $day['date'] ?? null;
            $label = $day['label'] ?? null;

            if (! is_string($dayId) || ! is_string($date)) {
                continue;
            }

            $days[] = [
                'day_id' => $dayId,
                'date' => $date,
                'label' => is_string($label) ? $label : null,
            ];
        }

        return $days;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'array',
        ];
    }
}
