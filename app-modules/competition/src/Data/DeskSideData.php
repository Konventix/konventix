<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One side of a match: the slot, who fills it, and the score recorded for
 * it. An empty slot is either `awaiting` — a match that feeds it is still
 * being played — or a bye nothing will ever fill; the desk records a match
 * only once none of its sides is awaited.
 */
#[TypeScript]
final class DeskSideData extends Data
{
    public function __construct(
        public readonly int $position,
        public readonly ?string $entrantId,
        public readonly ?string $teamName,
        public readonly ?int $score,
        public readonly bool $winner,
        public readonly bool $awaiting,
    ) {}
}
