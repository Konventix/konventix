<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One side of a match: the slot, who fills it (nobody yet, until the bracket
 * advances into it), and the score recorded for it.
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
    ) {}
}
