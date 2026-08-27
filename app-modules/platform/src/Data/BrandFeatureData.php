<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Features\BrandFeature;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One declared brand feature (ADR-0047), as the brand edit page's Features
 * card renders it — the registered feature plus its Pennant state for the
 * brand being edited.
 */
#[TypeScript]
final class BrandFeatureData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public bool $active,
    ) {}

    public static function ofFeature(BrandFeature $feature, bool $active): self
    {
        return new self(
            name: $feature->name,
            label: $feature->label,
            description: $feature->description,
            active: $active,
        );
    }
}
