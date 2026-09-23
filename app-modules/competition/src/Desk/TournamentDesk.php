<?php

declare(strict_types=1);

namespace Konventix\Competition\Desk;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Konventix\Competition\Actions\Concerns\RefusesOnceAResultIsRecorded;
use Konventix\Competition\Data\DeskDisputeData;
use Konventix\Competition\Data\DeskEntrantData;
use Konventix\Competition\Data\DeskMatchData;
use Konventix\Competition\Data\DeskPowersData;
use Konventix\Competition\Data\DeskReportData;
use Konventix\Competition\Data\DeskScoreEntryData;
use Konventix\Competition\Data\DeskSideData;
use Konventix\Competition\Data\DeskStageData;
use Konventix\Competition\Data\DeskStandingData;
use Konventix\Competition\Data\TournamentDeskData;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Seeding\SignupField;

/**
 * The tournament desk's read side (design §9, D-CMP-21): one competition as
 * the people running it need to see it.
 *
 * **This runs on the operator admin host**, which resolves no brand and
 * hardens `BrandScope` to zero rows (ADR-0029). Every query escapes the scope
 * and names the competition's own brand — never ambient context, and never a
 * relation, which would re-apply the scope on the related model
 * (.ai/rules/app-modules-src.md). The whole field's names, slots, reports and
 * standings are read in one query each rather than per match: a desk on a
 * sixty-four team bracket must not be sixty-four round trips.
 *
 * The seed list is `SignupField`'s — the same definition `GenerateBracket`
 * draws from — so the order the desk sees is the order the bracket gets.
 */
final class TournamentDesk
{
    use RefusesOnceAResultIsRecorded;

    public function __construct(
        private readonly SignupField $signupField,
    ) {}

    public function for(Competition $competition): TournamentDeskData
    {
        $stage = $this->stageOf($competition);

        /** @var Collection<int, Entrant> $entrants */
        $entrants = Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->get()
            ->keyBy('id');

        $teamNames = CompetitionTeam::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->whereIn('id', $entrants->pluck('competition_team_id')->unique())
            ->pluck('name', 'id');

        $nameOf = fn (?string $entrantId): string => $entrantId === null
            ? '—'
            : (string) ($teamNames[$entrants->get($entrantId)->competition_team_id ?? ''] ?? '—');

        $matches = $stage === null ? new Collection : CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('stage_id', $stage->id)
            // Double elimination's three brackets in the order they are
            // played; a group stage's `group-a`, `group-b`… and the one-bracket
            // formats' null fall through to the name.
            ->orderByRaw("CASE bracket WHEN 'winners' THEN 1 WHEN 'losers' THEN 2 WHEN 'grand_final' THEN 3 ELSE 0 END")
            ->orderBy('bracket')
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        $slots = MatchSlot::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->whereIn('competition_match_id', $matches->pluck('id'))
            ->orderBy('position')
            ->get()
            ->groupBy('competition_match_id');

        // Empty slots still fed by an undecided match, as `match|slot`: the
        // same rule `SettlesAMatch::guardEverySideHasArrived()` refuses on.
        $unfinished = $matches->where('state', '!=', MatchState::Finished)->pluck('id')->all();
        $awaited = MatchConnection::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->whereIn('source_match_id', $unfinished)
            ->get(['target_match_id', 'target_slot'])
            ->map(fn (MatchConnection $edge): string => $edge->target_match_id.'|'.$edge->target_slot)
            ->flip();

        return new TournamentDeskData(
            competitionId: (string) $competition->getKey(),
            name: $competition->name,
            status: $competition->status,
            startsAt: $competition->starts_at?->toIso8601String(),
            checkInMode: $competition->check_in_mode,
            resultSubmissionMode: $competition->result_submission_mode,
            checkedIn: $entrants->where('state', EntrantState::CheckedIn)->count(),
            stage: $stage === null ? null : $this->stageData($stage),
            field: $stage === null ? [] : array_map(
                function (string $entrantId) use ($entrants, $nameOf): DeskEntrantData {
                    /** @var Entrant $entrant */
                    $entrant = $entrants->get($entrantId);

                    return new DeskEntrantData(
                        id: $entrantId,
                        teamName: $nameOf($entrantId),
                        seed: $entrant->seed,
                        state: $entrant->state,
                    );
                },
                $this->signupField->entrantIds($stage),
            ),
            matches: $matches->map(fn (CompetitionMatch $match): DeskMatchData => new DeskMatchData(
                id: $match->id,
                bracket: $match->bracket,
                round: $match->round,
                position: $match->position,
                state: $match->state,
                scheduledAt: $match->scheduled_at?->toIso8601String(),
                disputed: $match->isDisputed(),
                sides: array_values(($slots->get($match->id) ?? new Collection)->map(
                    fn (MatchSlot $slot): DeskSideData => new DeskSideData(
                        position: $slot->position,
                        entrantId: $slot->entrant_id,
                        teamName: $slot->entrant_id === null ? null : $nameOf($slot->entrant_id),
                        score: $slot->score,
                        winner: $slot->entrant_id !== null && $slot->entrant_id === $match->winner_entrant_id,
                        awaiting: $slot->entrant_id === null && $awaited->has($match->id.'|'.$slot->position),
                    ),
                )->all()),
            ))->values()->all(),
            disputes: $this->disputes($competition, $matches->filter->isDisputed(), $nameOf),
            standings: $stage === null ? [] : $this->standings($stage, $nameOf),
            queue: $stage === null || ! $stage->isLeaderboard() ? [] : $this->queue($stage, $nameOf),
            powers: new DeskPowersData(
                checkInEntrants: Gate::allows(Permission::CheckInEntrants->value),
                recordResults: Gate::allows(Permission::RecordResults->value),
                correctResults: Gate::allows(Permission::CorrectResults->value),
                manageSchedule: Gate::allows(Permission::ManageSchedule->value),
            ),
        );
    }

    /**
     * The stage the desk runs: the first, and in this slice the only one
     * (§7.3 — the UI creates one stage per competition).
     */
    private function stageOf(Competition $competition): ?Stage
    {
        /** @var Stage|null */
        return Stage::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->orderBy('sequence')
            ->first();
    }

    private function stageData(Stage $stage): DeskStageData
    {
        $settings = $stage->isLeaderboard() ? $stage->leaderboardSettings() : null;

        return new DeskStageData(
            id: $stage->id,
            name: $stage->name,
            format: $stage->format,
            isLeaderboard: $stage->isLeaderboard(),
            generated: $stage->isGenerated(),
            hasResult: $this->hasRecordedResult($stage),
            heats: $settings?->heats,
            unit: $settings?->unit,
        );
    }

    /**
     * Every open dispute with both claims and the reason it was raised
     * (§8.4). The reason is the latest `disputed` history row's note — the
     * dispute Action writes it nowhere else.
     *
     * @param  Collection<int, CompetitionMatch>  $disputed
     * @param  callable(?string): string  $nameOf
     * @return list<DeskDisputeData>
     */
    private function disputes(Competition $competition, Collection $disputed, callable $nameOf): array
    {
        if ($disputed->isEmpty()) {
            return [];
        }

        $ids = $disputed->pluck('id');

        $reports = MatchResultReport::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->whereIn('competition_match_id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('competition_match_id');

        $reasons = MatchHistoryEntry::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->whereIn('competition_match_id', $ids)
            ->where('kind', MatchHistoryKind::Disputed->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('competition_match_id');

        return array_values($disputed->map(fn (CompetitionMatch $match): DeskDisputeData => new DeskDisputeData(
            matchId: $match->id,
            bracket: $match->bracket,
            round: $match->round,
            position: $match->position,
            disputedAt: $match->disputed_at?->toIso8601String() ?? '',
            reason: $reasons->get($match->id)?->payloadData()->note,
            recorded: $match->state === MatchState::Finished,
            reports: array_values(($reports->get($match->id) ?? new Collection)->map(
                fn (MatchResultReport $report): DeskReportData => new DeskReportData(
                    entrantId: $report->entrant_id,
                    teamName: $nameOf($report->entrant_id),
                    scores: $report->normalisedScores(),
                    proofUrl: $this->proofUrl($report->proof_media_id),
                    reportedAt: $report->updated_at?->toIso8601String() ?? '',
                ),
            )->all()),
        ))->all());
    }

    /**
     * @param  callable(?string): string  $nameOf
     * @return list<DeskStandingData>
     */
    private function standings(Stage $stage, callable $nameOf): array
    {
        return array_values(Standing::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->orderBy('group')
            ->orderByRaw('placement IS NULL')
            ->orderBy('placement')
            ->get()
            ->map(fn (Standing $row): DeskStandingData => new DeskStandingData(
                entrantId: $row->entrant_id,
                teamName: $nameOf($row->entrant_id),
                group: $row->group,
                placement: $row->placement,
                wins: $row->wins,
                losses: $row->losses,
                draws: $row->draws,
                points: $row->points,
                tiebreaker: $row->tiebreaker,
            ))
            ->all());
    }

    /**
     * The verification queue (§8.3): entries nobody has verified or turned
     * down yet, oldest first — the one that has waited longest is looked at
     * first.
     *
     * @param  callable(?string): string  $nameOf
     * @return list<DeskScoreEntryData>
     */
    private function queue(Stage $stage, callable $nameOf): array
    {
        return array_values(ScoreEntry::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->whereNull('verified_at')
            ->whereNull('rejected_at')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ScoreEntry $entry): DeskScoreEntryData => new DeskScoreEntryData(
                id: $entry->id,
                entrantId: $entry->entrant_id,
                teamName: $nameOf($entry->entrant_id),
                heat: $entry->heat,
                value: (float) $entry->value,
                note: $entry->note,
                proofUrl: $this->proofUrl($entry->proof_media_id),
                submittedAt: $entry->updated_at?->toIso8601String() ?? '',
            ))
            ->all());
    }

    /**
     * Where the desk can look at a proof: Platform's media route, which the
     * admin host serves too. Viewing only — the desk offers no upload.
     */
    private function proofUrl(?string $mediaId): ?string
    {
        return $mediaId === null ? null : route('platform.media.show', ['media' => $mediaId]);
    }
}
