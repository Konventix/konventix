<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a save would do to the projection, as counts (design §6.1 step 3 and
 * the `ValidatePlan` dry run): places added, changed (label, ref, category,
 * blocked, accessible, zone, group) and removed.
 */
#[TypeScript]
final class PlanDiffSummaryData extends Data
{
    public function __construct(
        public readonly int $added,
        public readonly int $changed,
        public readonly int $removed,
    ) {}
}
