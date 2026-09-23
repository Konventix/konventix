<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * What a side says the result of a head-to-head match was (design §8.2,
 * ADR-0009).
 *
 * `scores` is keyed by **slot position**, as §8.2 writes it — not by entrant.
 * A correction re-routes entrants through the progression graph (§8.5), so
 * the slot is the stable thing over the life of a match, and two reports
 * agreeing is then a comparison of two maps with the same keys.
 *
 * What is deliberately absent: who is reporting. The reporting side and the
 * reporting user are arguments to the Action, resolved from the session and
 * from roster membership, never from a form — a `Data` object that could name
 * the reporter would be a second way to report on somebody else's behalf.
 */
final class MatchReportData extends Data
{
    /**
     * @param  array<int|string, int|null>  $scores  slot position => score
     */
    public function __construct(
        public readonly array $scores,

        /** Platform media (ADR-0035); the competition's policy decides whether it is owed. */
        #[Nullable, Ulid]
        public readonly ?string $proofMediaId = null,

        /** The reporting side's own remark, shown beside the claim. */
        #[Nullable, StringType, Max(255)]
        public readonly ?string $note = null,
    ) {}

    /**
     * The same bounds the desk's score line has (`DeskScoreLineData`): a
     * score is a whole number from 0, or empty. Before the final review a
     * negative number or a string passed, and a claim of two empty scores
     * reached the settle path as a draw (final review, part 1, I1).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'min:1'],
            'scores.*' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The claim as `position => score`, integer keys, ascending.
     *
     * A form posts its keys as strings and a json column hands them back as
     * strings; normalising in one place is what keeps "do the two sides
     * agree?" an identity comparison rather than a loop with casts in it.
     *
     * @return array<int, int|null>
     */
    public function normalisedScores(): array
    {
        $scores = [];

        foreach ($this->scores as $position => $score) {
            $scores[(int) $position] = $score === null ? null : (int) $score;
        }

        ksort($scores);

        return $scores;
    }
}
