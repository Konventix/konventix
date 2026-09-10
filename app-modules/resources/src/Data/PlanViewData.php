<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Models\Plan;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A plan as the picker renders it (design §7.4): dimensions, background
 * and the whole document. Read-only; the editor's props are `PlanData` plus
 * the document with a version.
 */
#[TypeScript]
final class PlanViewData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $floorLabel,
        public readonly int $widthMm,
        public readonly int $heightMm,
        public readonly ?BackgroundData $background,
        public readonly PlanDocumentData $document,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        return new self(
            id: $plan->id,
            name: $plan->name,
            floorLabel: $plan->floor_label,
            widthMm: $plan->width_mm,
            heightMm: $plan->height_mm,
            background: $plan->background,
            document: $plan->document,
        );
    }
}
