<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The signed-in person's relation to a match (design §8.2, §10): which slot
 * their team plays in, the two claims filed so far, and which of report,
 * confirm and dispute the page offers them. The Actions decide again.
 */
#[TypeScript]
final class MatchViewerData extends Data
{
    /**
     * @param  array<int, int|null>|null  $ownReport  slot position => score
     * @param  array<int, int|null>|null  $opposingReport  slot position => score
     */
    public function __construct(
        /** The viewer's slot, null when they are not on a side. */
        public readonly ?int $position,
        public readonly ?array $ownReport,
        public readonly ?array $opposingReport,
        public readonly bool $canReport,
        public readonly bool $canConfirm,
        public readonly bool $canDispute,
    ) {}
}
