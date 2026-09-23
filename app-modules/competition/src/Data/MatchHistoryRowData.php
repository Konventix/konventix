<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\MatchHistoryKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of a match's history as its sides read it (design §8.6): what
 * happened, when, for which team, with the score line and the note where the
 * kind carries one. The actor is not named: a side needs to know that its
 * opponent reported, not which of their accounts pressed the button.
 */
#[TypeScript]
final class MatchHistoryRowData extends Data
{
    /**
     * @param  array<int, int|null>|null  $scores  slot position => score
     */
    public function __construct(
        public readonly MatchHistoryKind $kind,
        public readonly ?string $at,
        public readonly ?string $teamName,
        public readonly ?array $scores,
        public readonly ?string $note,
    ) {}
}
