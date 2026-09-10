<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Models\Plan;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The plan form of the console (design §8.2 create dialog): event, name,
 * floor, size and the editor's display settings. The document is not part
 * of it — that goes through `PlanSaveData`.
 */
#[TypeScript]
final class PlanData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $floorLabel,

        #[Required, IntegerType, Min(1)]
        public readonly int $widthMm,

        #[Required, IntegerType, Min(1)]
        public readonly int $heightMm,

        #[Required, StringType, In(['mm', 'cm', 'dm', 'm'])]
        public readonly string $unitDisplay = 'cm',

        #[Required, IntegerType, In([1, 10, 100, 1000])]
        public readonly int $snapStep = 100,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        return new self(
            eventId: $plan->event_id,
            name: $plan->name,
            floorLabel: $plan->floor_label,
            widthMm: $plan->width_mm,
            heightMm: $plan->height_mm,
            unitDisplay: $plan->unit_display,
            snapStep: $plan->snap_step,
        );
    }
}
