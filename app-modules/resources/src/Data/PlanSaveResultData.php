<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The 200 of a successful save (design §6.1): the new version the editor
 * must send as `expectedVersion` next time, the places whose projection
 * changed (the same list `PlanSaved` carries), the warnings collected on
 * the way, and when the save happened — the save pill renders `savedAt`
 * (design §8.3), and `SavePlan` fills it with `now()->toIso8601String()`.
 */
#[TypeScript]
final class PlanSaveResultData extends Data
{
    /**
     * @param  list<string>  $changedPlaceIds
     * @param  list<PlanWarningData>  $warnings
     */
    public function __construct(
        public readonly int $version,

        /** @var list<string> */
        public readonly array $changedPlaceIds,

        /** @var list<PlanWarningData> */
        #[DataCollectionOf(PlanWarningData::class)]
        public readonly array $warnings,

        public readonly string $savedAt,
    ) {}
}
