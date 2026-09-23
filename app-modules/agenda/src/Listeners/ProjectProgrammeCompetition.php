<?php

declare(strict_types=1);

namespace Konventix\Agenda\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Agenda\Enums\CompetitionBlockState;
use Konventix\Agenda\Models\ProgrammeCompetition;
use Konventix\Competition\Events\CompetitionAnnounced;
use Konventix\Competition\Events\CompetitionCancelled;
use Konventix\Competition\Events\CompetitionPublished;
use Konventix\Competition\Events\CompetitionRescheduled;
use Konventix\Competition\Events\CompetitionUnpublished;

/**
 * Keeps `agenda_competitions`, Agenda's read model of the event's publicly
 * announced competitions (timetable design §4.5, D-AGD-03), in step with
 * Competition's five contract events. Every
 * `Konventix\Competition\Events\*` import is the one sanctioned
 * cross-module dependency (ADR-0004); nothing past this line sees
 * Competition's models.
 *
 * Only the read model is touched. The organizer's selection
 * (`CompetitionEntry`, D-AGD-02) belongs to the programme editor, and
 * Competition may not delete it: unpublishing removes the block, and
 * republishing brings it back where the organizer put it.
 *
 * Every handler is idempotent — a replayed lifecycle event or a second run
 * of `competition:announce-competitions` leaves the same row.
 *
 * Deliberately **not** short-circuited on the Pennant gate, the reasoning
 * `ProjectEventSummary` gives: a brand that switches Agenda on after its
 * tournaments were published must find them selectable, not wait for
 * somebody to run a backfill. Nothing *acts* on this row while the module
 * is off; it holds a name and a time the competition's own public page
 * already shows.
 *
 * `withoutBrandScope()` and the payload's `brandId` throughout: a contract
 * event is handled wherever it was fired — a console command, a queued job,
 * a request on the operator admin host where the scope hardens to zero rows
 * (ADR-0029) — and the deferred `afterCommit()` dispatch restores no brand
 * context (ADR-0010).
 */
final class ProjectProgrammeCompetition
{
    public function handlePublished(CompetitionPublished $event): void
    {
        $this->upsert(
            brandId: $event->brandId,
            eventId: $event->eventId,
            competitionId: $event->competitionId,
            name: $event->name,
            slug: $event->slug,
            startsAt: $event->startsAt,
            state: CompetitionBlockState::Published,
        );
    }

    /**
     * Moves an existing block only. The event carries no name, so an unknown
     * competition cannot be created from it; the backfill is the repair.
     */
    public function handleRescheduled(CompetitionRescheduled $event): void
    {
        $row = $this->find($event->brandId, $event->competitionId);

        if ($row === null) {
            return;
        }

        $row->forceFill(['starts_at' => self::instant($event->startsAt)])->save();
    }

    /**
     * Strikes an existing block and keeps it (D-AGD-08's reasoning). A
     * competition cancelled straight from draft was never announced and has
     * no block here — the event's own docblock warns of exactly this — so an
     * unknown competition is ignored rather than created.
     */
    public function handleCancelled(CompetitionCancelled $event): void
    {
        $row = $this->find($event->brandId, $event->competitionId);

        if ($row === null) {
            return;
        }

        $row->forceFill(['state' => CompetitionBlockState::Cancelled])->save();
    }

    /**
     * Removes the block from the programme at once (competition design §14).
     * The organizer's `CompetitionEntry` stays: the editor shows it as
     * withdrawn, and a republish restores it.
     */
    public function handleUnpublished(CompetitionUnpublished $event): void
    {
        $this->find($event->brandId, $event->competitionId)?->delete();
    }

    /**
     * The backfill's restatement: `announced: false` (a draft, or cancelled
     * before it was ever published) unlearns the competition; otherwise the
     * row is written as `CompetitionPublished` would write it, struck when
     * Competition says it is cancelled.
     */
    public function handleAnnounced(CompetitionAnnounced $event): void
    {
        if (! $event->announced) {
            $this->find($event->brandId, $event->competitionId)?->delete();

            return;
        }

        $this->upsert(
            brandId: $event->brandId,
            eventId: $event->eventId,
            competitionId: $event->competitionId,
            name: $event->name,
            slug: $event->slug,
            startsAt: $event->startsAt,
            state: $event->status === 'cancelled' ? CompetitionBlockState::Cancelled : CompetitionBlockState::Published,
        );
    }

    private function find(string $brandId, string $competitionId): ?ProgrammeCompetition
    {
        return ProgrammeCompetition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('competition_id', $competitionId)
            ->first();
    }

    /**
     * Keyed on `competition_id`, so a replay rewrites the same row. Every
     * field is written, `null` included: the payload is the whole truth
     * about the block, and a start Competition has cleared must clear here.
     */
    private function upsert(
        string $brandId,
        string $eventId,
        string $competitionId,
        string $name,
        string $slug,
        ?string $startsAt,
        CompetitionBlockState $state,
    ): void {
        $row = $this->find($brandId, $competitionId)
            ?? new ProgrammeCompetition(['competition_id' => $competitionId]);

        $row->forceFill([
            'brand_id' => $brandId,
            'event_id' => $eventId,
            'name' => $name,
            'slug' => $slug,
            'starts_at' => self::instant($startsAt),
            'state' => $state,
        ])->save();
    }

    /**
     * Anchored to UTC, the way `SaveAgendaItem` anchors an item's times, so
     * the stored moment is the one Competition meant whatever offset its
     * ISO-8601 string carried.
     */
    private static function instant(?string $iso): ?CarbonImmutable
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->utc();
    }
}
