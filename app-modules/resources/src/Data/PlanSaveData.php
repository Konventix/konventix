<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the editor sends on save and on validate (design §6.1): the version
 * it was based on, the whole document, and the resolutions it collected in
 * the conflict dialog. `expectedVersion !== plan.version` is a 409 before
 * anything else is looked at (optimistic lock, ADR-0060 point 4).
 */
#[TypeScript]
final class PlanSaveData extends Data
{
    /**
     * @param  list<PlanConflictResolutionData>  $resolutions
     */
    public function __construct(
        #[Required, IntegerType, Min(0)]
        public readonly int $expectedVersion,

        #[Required]
        public readonly PlanDocumentData $document,

        /** @var list<PlanConflictResolutionData> */
        #[Present, DataCollectionOf(PlanConflictResolutionData::class)]
        public readonly array $resolutions = [],
    ) {}
}
