<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One side of a match on the public bracket (design §10). An empty slot is
 * `awaiting` while a match that feeds it is undecided, and a bye otherwise —
 * the same reading the desk has (`DeskSideData`). `teamId` is what the team
 * page is linked by.
 */
#[TypeScript]
final class BracketSideData extends Data
{
    public function __construct(
        public readonly int $position,
        public readonly ?string $entrantId,
        public readonly ?string $teamId,
        public readonly ?string $teamName,
        public readonly ?int $score,
        public readonly bool $winner,
        public readonly bool $awaiting,
    ) {}
}
