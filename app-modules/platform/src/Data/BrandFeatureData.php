<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Features\BrandFeature;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One declared brand feature (ADR-0047), as the brand edit page's Features
 * card renders it — the registered feature plus its Pennant state for the
 * brand being edited.
 *
 * `settingsComponent`/`settingsProps` carry the feature's own contribution
 * to that same page (`BrandFeature`'s doc) — resolved only while `active`
 * is true, so an inactive feature's settings closure never runs against a
 * brand that has not opted in.
 */
#[TypeScript]
final class BrandFeatureData extends Data
{
    /**
     * @param  array<string, mixed>|null  $settingsProps
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public bool $active,
        public ?string $settingsComponent,
        public ?array $settingsProps,
    ) {}

    public static function ofFeature(BrandFeature $feature, bool $active, Brand $brand): self
    {
        return new self(
            name: $feature->name,
            label: $feature->label,
            description: $feature->description,
            active: $active,
            settingsComponent: $active ? $feature->settingsComponent : null,
            settingsProps: $active && $feature->settingsProps !== null ? ($feature->settingsProps)($brand) : null,
        );
    }
}
