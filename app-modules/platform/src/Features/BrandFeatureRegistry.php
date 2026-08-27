<?php

declare(strict_types=1);

namespace Konventix\Platform\Features;

/**
 * Every per-brand feature a module declares (ADR-0047), registered at boot
 * through `ModuleServiceProvider::registerBrandFeatures()`. Mirrors
 * `CookieRegistry`: registration is unconditional, and the brand page's
 * Features card is the only reader.
 */
final class BrandFeatureRegistry
{
    /** @var list<BrandFeature> */
    private array $features = [];

    public function register(BrandFeature ...$features): void
    {
        foreach ($features as $feature) {
            $this->features[] = $feature;
        }
    }

    /** @return list<BrandFeature> */
    public function all(): array
    {
        return $this->features;
    }
}
