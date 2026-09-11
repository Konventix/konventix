<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The answer of `ValidatePlan` (design §6.1): steps 0–5 of the save
 * pipeline without the write, so the editor can open the conflict dialog
 * before it commits to anything.
 */
#[TypeScript]
final class PlanValidationData extends Data
{
    /**
     * @param  list<PlanConflictData>  $conflicts
     * @param  list<PlanWarningData>  $warnings
     */
    public function __construct(
        /** @var list<PlanConflictData> */
        #[DataCollectionOf(PlanConflictData::class)]
        public readonly array $conflicts,

        /** @var list<PlanWarningData> */
        #[DataCollectionOf(PlanWarningData::class)]
        public readonly array $warnings,

        public readonly PlanDiffSummaryData $diff,
    ) {}
}
