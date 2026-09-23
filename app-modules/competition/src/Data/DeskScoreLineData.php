<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * The desk's result dialog (design §8.2, §8.4, §8.5, §9): a score per side,
 * the winner it decides on, and a note.
 *
 * One form for the dialog's three uses, because the dialog is one:
 *
 * - **record** and **resolve** key `scores` by slot position — §8.2's shape,
 *   the one `ResolveDispute` and every report take;
 * - **correct** keys them by entrant ULID, which is what `CorrectMatchResult`
 *   has always taken (a correction rewrites the scores of the entrants who
 *   played, whichever slot the cascade later moves).
 *
 * The winner is named, not derived: the desk may award a match on something
 * the score line does not show, and null is a draw. The note is mandatory for
 * a resolution and for a correction that voids a downstream result — the
 * Actions say so, so this only bounds its length.
 */
final class DeskScoreLineData extends Data
{
    /**
     * @param  array<int|string, int|null>  $scores
     */
    public function __construct(
        public readonly array $scores,

        #[Nullable, Ulid]
        public readonly ?string $winnerEntrantId = null,

        #[Nullable, StringType, Max(500)]
        public readonly ?string $note = null,
    ) {}

    /**
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
     * The scores keyed by slot position, for record and resolve.
     *
     * @return array<int, int|null>
     */
    public function scoresByPosition(): array
    {
        $scores = [];

        foreach ($this->scores as $position => $score) {
            $scores[(int) $position] = $score === null ? null : (int) $score;
        }

        ksort($scores);

        return $scores;
    }

    /**
     * The scores keyed by entrant ULID, for a correction.
     *
     * @return array<string, int|null>
     */
    public function scoresByEntrant(): array
    {
        $scores = [];

        foreach ($this->scores as $entrantId => $score) {
            $scores[(string) $entrantId] = $score === null ? null : (int) $score;
        }

        return $scores;
    }
}
