<?php

declare(strict_types=1);

namespace Konventix\Competition\Pages;

use Konventix\Competition\Data\MyScoreEntryData;
use Konventix\Competition\Data\PendingConfirmationData;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\ScoreEntry;

/**
 * The two results sections of "Meine Turniere" (design §10): reports the
 * other side filed that the person's side still has to answer, and their
 * leaderboard scores with the desk's verdict on each.
 *
 * Runs on the brand host, so `BrandScope` stays on (ADR-0010); the entrant
 * ULIDs it is handed are the person's own, read from their rosters by
 * `MyTournamentsController`.
 */
final class MyOpenResults
{
    /**
     * "Pending reports to confirm" (§10): matches of the given entrants where
     * another side has filed a claim, theirs has not, and the match is still
     * open and undisputed.
     *
     * @param  list<string>  $entrantIds  the viewer's entrants
     * @return list<PendingConfirmationData>
     */
    public function pendingConfirmations(array $entrantIds): array
    {
        $reports = (new MatchResultReport)->getTable();
        $slots = (new MatchSlot)->getTable();

        $theirs = MatchResultReport::query()
            ->whereIn('competition_match_id', MatchSlot::query()
                ->select('competition_match_id')
                ->whereIn('entrant_id', $entrantIds))
            ->whereNotIn('entrant_id', $entrantIds)
            // Only a claim by a side that stands in the match now, the rule
            // of the match page and the Actions (`MatchPowers::currentClaims()`,
            // fix wave B, item 11): a team moved out of the slot after it
            // reported waits on nobody's confirmation.
            ->whereExists(MatchSlot::query()
                ->selectRaw('1')
                ->whereColumn("{$slots}.competition_match_id", "{$reports}.competition_match_id")
                ->whereColumn("{$slots}.entrant_id", "{$reports}.entrant_id"))
            ->whereNotIn('competition_match_id', MatchResultReport::query()
                ->select('competition_match_id')
                ->whereIn('entrant_id', $entrantIds))
            ->orderBy('created_at')
            ->get();

        $matches = CompetitionMatch::query()
            ->whereIn('id', $theirs->pluck('competition_match_id'))
            ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])
            ->get()
            ->reject(fn (CompetitionMatch $match): bool => $match->isDisputed())
            ->keyBy('id');

        $competitions = Competition::query()->whereIn('id', $matches->pluck('competition_id')->unique())->get()->keyBy('id');
        // Only an event whose tournament pages are public gets a link; the
        // rest would be a link to a 404.
        $slugs = EventSummary::query()
            ->whereIn('event_id', $competitions->pluck('event_id')->unique())
            ->get()
            ->filter(fn (EventSummary $event): bool => $event->isPublic())
            ->pluck('slug', 'event_id');
        $teamIds = Entrant::query()->whereIn('id', $theirs->pluck('entrant_id'))->pluck('competition_team_id', 'id');
        $teamNames = CompetitionTeam::query()->whereIn('id', $teamIds->unique())->pluck('name', 'id');

        return array_values($theirs
            ->filter(fn (MatchResultReport $report): bool => $matches->has($report->competition_match_id))
            ->map(function (MatchResultReport $report) use ($matches, $competitions, $slugs, $teamIds, $teamNames): PendingConfirmationData {
                /** @var CompetitionMatch $match */
                $match = $matches->get($report->competition_match_id);
                /** @var Competition $competition */
                $competition = $competitions->get($match->competition_id);

                return new PendingConfirmationData(
                    eventSlug: $slugs[$competition->event_id] ?? null,
                    competitionSlug: $competition->slug,
                    competitionName: $competition->name,
                    matchId: $match->id,
                    opponentName: (string) ($teamNames[$teamIds[$report->entrant_id] ?? ''] ?? '—'),
                    scores: $report->normalisedScores(),
                );
            })
            ->all());
    }

    /**
     * "Score entries and their verification state" (§10), newest first.
     *
     * @param  list<string>  $entrantIds  the viewer's entrants
     * @return list<MyScoreEntryData>
     */
    public function scoreEntries(array $entrantIds): array
    {
        $entries = ScoreEntry::query()
            ->whereIn('entrant_id', $entrantIds)
            ->orderByDesc('updated_at')
            ->get();

        $entrants = Entrant::query()->whereIn('id', $entries->pluck('entrant_id')->unique())->get()->keyBy('id');
        $competitionNames = Competition::query()->whereIn('id', $entrants->pluck('competition_id')->unique())->pluck('name', 'id');
        $teamNames = CompetitionTeam::query()->whereIn('id', $entrants->pluck('competition_team_id')->unique())->pluck('name', 'id');

        return array_values($entries->map(function (ScoreEntry $entry) use ($entrants, $competitionNames, $teamNames): MyScoreEntryData {
            /** @var Entrant $entrant */
            $entrant = $entrants->get($entry->entrant_id);

            return new MyScoreEntryData(
                competitionName: (string) ($competitionNames[$entrant->competition_id] ?? '—'),
                teamName: (string) ($teamNames[$entrant->competition_team_id] ?? '—'),
                heat: $entry->heat,
                value: (float) $entry->value,
                state: match (true) {
                    $entry->isVerified() => 'verified',
                    $entry->isRejected() => 'rejected',
                    default => 'pending',
                },
                rejectionNote: $entry->rejection_note,
            );
        })->all());
    }
}
