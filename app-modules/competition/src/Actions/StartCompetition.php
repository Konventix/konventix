<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RefusesOnceAResultIsRecorded;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Events\CompetitionStarted;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Seeding\SignupField;

/**
 * Starts the tournament: `SignupClosed → Running` (D-CMP-15, design §4).
 *
 * Two preconditions beyond the transition table, both from §4:
 *
 * - **A generated stage.** `Running` means "the first stage has been
 *   generated and started" — a competition whose bracket has not been drawn
 *   has nothing to record results against, and the desk would show an empty
 *   board. A **leaderboard** stage counts as ready without a draw: it has no
 *   matches (§7.2), `GenerateBracket` has no generator for it, and the desk
 *   offers it no draw button. Its field is whoever signed up, and scores
 *   are entered against that. Before this, a leaderboard competition could
 *   never reach `Running` and therefore never `Finished` (design §14,
 *   "A leaderboard needs no draw to start").
 * - **No `incomplete` entrant.** An incomplete roster holds a slot until the
 *   organizer fills it from the interested list or declines it (§5.4).
 *   Starting over one would either seed a team that cannot field itself or
 *   silently drop it — bracket generation never seeds one either.
 *
 * - **The drawn field is the field.** The bracket is drawn in `SignupClosed`,
 *   and several legal moves change the field after that: a withdrawal, a
 *   declined incomplete roster, a roster change that makes an entrant
 *   incomplete, a late check-in or an undone one. The stage keeps the field
 *   it was drawn from (`drawn_entrant_ids`), and a start over a different
 *   field is refused — the desk draws again first, which costs nothing
 *   before the first result (final review, part 1, I4). A **retired**
 *   entrant — withdrawn or disqualified — that stands in no open match any
 *   more is the exception: its matches were forfeited, which is how the
 *   bracket already deals with it, and a forfeit is a recorded result that
 *   would forbid the redraw. A withdrawal or decline after a result forfeits
 *   the same way, so it cannot strand the start either (fix wave A round 2,
 *   N2).
 *
 * All of them are refusals, not warnings: they are the ways a tournament gets
 * started on a field that is not the field that will play it. They are asked
 * under the competition's row lock, which every draw Action takes as well,
 * so a draw cannot slip in between the checks and the transition.
 */
final class StartCompetition
{
    use RefusesOnceAResultIsRecorded;
    use TransitionsCompetitionStatus;

    public function __construct(
        private readonly SignupField $field,
    ) {}

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            Competition::withoutBrandScope()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();
            $competition->refresh();

            $first = $this->guardGeneratedStage($competition);
            // An incomplete roster first: filling it puts the entrant back in
            // the field and needs no redraw, so it is the refusal to act on.
            $this->guardCompleteRosters($competition);
            $this->guardDrawnFieldIsTheField($competition, $first);

            $competition = $this->transition($competition, CompetitionStatus::Running);

            DB::afterCommit(static function () use ($competition): void {
                event(new CompetitionStarted(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    disciplineId: $competition->discipline_id,
                    brandId: $competition->brand_id,
                    status: $competition->status->value,
                    at: now()->toIso8601String(),
                ));
            });

            return $competition;
        });
    }

    /**
     * The **first** stage decides, because `Running` means the first stage
     * is drawn and started (§4). A leaderboard counts as ready undrawn; a
     * later leaderboard stage behind an undrawn bracket does not (review
     * fix round, M1).
     */
    private function guardGeneratedStage(Competition $competition): Stage
    {
        /** @var Stage|null $first */
        $first = Stage::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();

        $ready = $first !== null && ($first->generated_at !== null || $first->format === StageFormat::Leaderboard->value);

        if (! $ready) {
            throw IllegalCompetitionStateTransitionException::forPrecondition(
                $competition->id,
                'no stage has been generated yet — draw the bracket first.',
            );
        }

        return $first;
    }

    /**
     * The first stage's drawn field against the field as it stands now, both
     * as sets. A leaderboard is drawn from nobody (its field is whoever signed
     * up), and a stage drawn by a path that kept no field — a test fixture
     * stamping `generated_at` — has nothing to compare, so both pass.
     */
    private function guardDrawnFieldIsTheField(Competition $competition, Stage $first): void
    {
        $drawn = $first->drawn_entrant_ids;

        if ($first->format === StageFormat::Leaderboard->value || $drawn === null) {
            return;
        }

        $field = $this->field->entrantIds($first);

        // Retired entrants — withdrawn or disqualified — that no longer
        // stand in any open match: the bracket has already dealt with them
        // (a forfeit, or a slot emptied in front of an opponent still to
        // come), and once a result stands no redraw could (fix wave A round
        // 2, N2). A withdrawn team still in an open match is a change the
        // desk has to draw again for.
        $settled = array_map(strval(...), Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->id)
            ->whereIn('state', [EntrantState::Withdrawn->value, EntrantState::Disqualified->value])
            ->whereNotIn('id', MatchSlot::withoutBrandScope()
                ->select('entrant_id')
                ->where('brand_id', $competition->brand_id)
                ->whereNotNull('entrant_id')
                ->whereIn('competition_match_id', CompetitionMatch::withoutBrandScope()
                    ->select('id')
                    ->where('brand_id', $competition->brand_id)
                    ->where('competition_id', $competition->id)
                    ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])))
            ->pluck('id')
            ->all());

        $undrawn = array_diff($field, $drawn);
        $gone = array_diff($drawn, $field, $settled);

        if ($undrawn !== [] || $gone !== []) {
            // Once a result stands the bracket cannot be drawn again, so the
            // refusal must not say so (fix wave A round 3, M-f). With the
            // retired swept out of it (`ForfeitRetiredEntrants`), what is
            // left is a check-in that moved after the draw.
            $remedy = $this->hasRecordedResult($first)
                ? 'the bracket already has a result and cannot be drawn again: check the entrants in or out to match the draw, or withdraw the ones no longer in the field.'
                : 'draw the bracket again first.';

            throw IllegalCompetitionStateTransitionException::forPrecondition(
                $competition->id,
                sprintf(
                    'the field changed after the bracket was drawn (%d in the field but not drawn, %d drawn but no longer in the field) — %s',
                    count($undrawn),
                    count($gone),
                    $remedy,
                ),
            );
        }
    }

    private function guardCompleteRosters(Competition $competition): void
    {
        $incomplete = Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->id)
            ->where('state', EntrantState::Incomplete->value)
            ->count();

        if ($incomplete > 0) {
            throw IllegalCompetitionStateTransitionException::forPrecondition(
                $competition->id,
                sprintf(
                    '%d roster(s) are still incomplete — fill them from the interested list or decline them.',
                    $incomplete,
                ),
            );
        }
    }
}
