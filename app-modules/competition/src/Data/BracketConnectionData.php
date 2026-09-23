<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One edge of the progression graph (§7.4): the winner or loser of
 * `sourceMatchId` goes into slot `targetSlot` of `targetMatchId`. The canvas
 * draws the lines of the bracket from these rather than guessing them from
 * positions, which is what LanBrackets' renderer did.
 */
#[TypeScript]
final class BracketConnectionData extends Data
{
    public function __construct(
        public readonly string $sourceMatchId,
        public readonly string $outcome,
        public readonly string $targetMatchId,
        public readonly int $targetSlot,
    ) {}
}
