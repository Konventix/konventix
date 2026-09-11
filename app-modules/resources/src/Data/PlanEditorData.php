<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything the plan editor page needs in one prop (ADR-0009): the plan
 * as the viewer sees it, the version the next save must name, the grid
 * step, the event's categories for the inspector's select, and the
 * occupancy the inspector shows before anyone touches a place.
 *
 * `publishedAt` is an ISO-8601 instant and the page only uses it as a flag
 * (.ai/rules/js.md: never print a raw timestamp).
 */
#[TypeScript]
final class PlanEditorData extends Data
{
    /**
     * @param  list<CategoryData>  $categories
     */
    public function __construct(
        public readonly PlanViewData $plan,
        public readonly string $eventId,
        public readonly int $version,
        public readonly int $snapStep,
        public readonly string $unitDisplay,
        public readonly ?string $publishedAt,
        public readonly bool $canReserve,
        #[DataCollectionOf(CategoryData::class)]
        public readonly array $categories,
        public readonly PlanOccupancyData $occupancy,
    ) {}
}
