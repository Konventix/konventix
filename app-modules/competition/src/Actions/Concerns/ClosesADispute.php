<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\RecordMatchHistory;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Events\MatchDisputeResolved;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * A dispute ends when the match it is about is settled, whoever settles it
 * (design §8.4; final review, part 1, I5).
 *
 * `ResolveDispute` is the desk's door, with its mandatory note. But a
 * disputed match can also be settled by the sides agreeing after all — a
 * confirm, or a re-report that now matches the other side's claim — or by a
 * desk correction of a result somebody contested. Each of those left
 * `dispute_resolved_at` null before the final review: the match was finished
 * and still "disputed", the desk's open-dispute panel kept it, and
 * `KonventixCompetitionDisputeWaiting` fired thirty minutes later for a match
 * nobody was arguing about.
 *
 * Closing one writes what `ResolveDispute` writes: the timestamp, a `resolved`
 * history row (which requires a note — the sides read it on the match page,
 * so a default is German, ADR-0001) and `MatchDisputeResolved` after the
 * commit.
 */
trait ClosesADispute
{
    protected function closeDispute(
        CompetitionMatch $match,
        ?string $winnerEntrantId,
        ?string $closedByUserId,
        string $note,
    ): void {
        if (! $match->isDisputed()) {
            return;
        }

        $match->forceFill(['dispute_resolved_at' => now()])->save();

        app(RecordMatchHistory::class)->handle(
            $match,
            MatchHistoryKind::Resolved,
            new MatchHistoryPayload(winnerEntrantId: $winnerEntrantId, note: $note),
            $closedByUserId,
        );

        // The contract names who ended the dispute; a path with nobody to
        // name (none today) sends nothing rather than an empty ULID.
        if ($closedByUserId === null) {
            return;
        }

        $resolved = new MatchDisputeResolved(
            matchId: $match->id,
            competitionId: $match->competition_id,
            brandId: $match->brand_id,
            entrantId: $winnerEntrantId,
            userId: $closedByUserId,
            at: now()->toIso8601String(),
        );

        DB::afterCommit(static function () use ($resolved): void {
            event($resolved);
        });
    }
}
