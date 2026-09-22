<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
 * The timestamps are `CarbonImmutable` and not `Illuminate\Support\Carbon`:
 * `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so that is
 * what the framework hands back — and PHPStan believes the annotation
 * rather than checking it (.ai/rules/src.md, #312).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $timezone
 * @property string|null $state
 * @property array<int, mixed>|null $days
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
     * an event that predates this module has emitted no lifecycle event
     * since, so it arrives here stateless and its programme has no public
     * page until its next transition — which for an already-published event
     * may be never. That is a gap in the *backfill*, not a reason to open
     * the page, and the backfill is where it was closed:
     * `event:replay-details --with-state` re-emits each event's current
     * lifecycle event, and is the rollout step for a brand activating
     * Agenda on events it already had (`docs/operations.md` § Programme).
     * The same run re-emits `EventDaysChanged`, which the `days` column has
     * the identical gap in — and that one opens the page on nothing rather
     * than refusing it, which is the harder half to notice.
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
