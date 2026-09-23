<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Stage;

/**
 * What a result may say about who won (design §7.1, §8.2, §8.4, §8.5).
 *
 * Two rules that every path writing a winner has to keep, whoever took it:
 * the winner is one of the match's sides, and a draw (no winner) happens only
 * where the bracket can carry one. Until the final review they were checked
 * on two of the six paths that settle a match — the desk's record and its
 * dispute resolution — and a participant confirm, an agreeing counter-report
 * or a desk correction could finish an elimination match with nobody to send
 * on, or send on an entrant who never played it (final review, part 1, I1).
 *
 * So they live here, and `SettlesAMatch::settleMatch()` and
 * `CorrectMatchResult` both call them: a new settle path inherits them rather
 * than having to remember them.
 */
trait GuardsMatchOutcome
{
    /**
     * A named winner has to stand in one of the match's slots. A stale form
     * or a mistyped ULID would otherwise be written to `winner_entrant_id`
     * and `AdvanceBracket` would push an entrant who is not in this match
     * into the next round.
     *
     * @param  array<int|string, string>  $sides  the entrant ULIDs in the match's filled slots
     */
    protected function guardWinnerIsASide(CompetitionMatch $match, ?string $winnerEntrantId, array $sides): void
    {
        if ($winnerEntrantId !== null && ! in_array($winnerEntrantId, $sides, true)) {
            throw ResultSubmissionException::notASide($match->id, $winnerEntrantId);
        }
    }

    /**
     * Refuses a draw (a null winner) where none is possible: always on an
     * elimination bracket, which cannot send "nobody" on to the next round
     * and would dead-end; elsewhere unless both the discipline (ADR-0062,
     * the source of record for draws) and the stage's format settings allow
     * one. A bye is never a draw — its lone side is the winner.
     */
    protected function guardDrawIsPossible(CompetitionMatch $match, ?string $winnerEntrantId): void
    {
        if ($winnerEntrantId !== null) {
            return;
        }

        /** @var Stage $stage */
        $stage = Stage::withoutBrandScope()->whereKey($match->stage_id)->firstOrFail();

        /** @var Competition $competition */
        $competition = Competition::withoutBrandScope()->whereKey($match->competition_id)->firstOrFail();

        /** @var Discipline $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($competition->discipline_id)->firstOrFail();

        $elimination = in_array($stage->stageFormat(), [StageFormat::SingleElimination, StageFormat::DoubleElimination], true);

        $settings = app(FormatRegistry::class)->has($stage->format)
            ? array_merge(app(FormatRegistry::class)->get($stage->format)->defaults(), $stage->settings ?? [])
            : [];

        if ($elimination || ! $discipline->allow_draws || ! (bool) ($settings['allow_draws'] ?? false)) {
            throw ResultSubmissionException::drawNotPossible($match->id, $stage->format);
        }
    }
}
