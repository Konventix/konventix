<?php

declare(strict_types=1);

namespace Konventix\Platform\Locales;

use Illuminate\Contracts\Translation\Loader;

/**
 * Laravel knows one fallback locale. ADR-0068 §8 needs a chain: a
 * variant (`de_formal`) holds only the lines whose address differs and
 * reads its base (`de`) for the rest. This decorator merges the base
 * catalogs beneath the variant's for the JSON group; everything else
 * passes through untouched.
 *
 * Registered with `$app->extend('translation.loader')` in
 * PlatformServiceProvider::register() — the first rung of the plan's
 * ladder was enough: the translator resolves its loader lazily, after
 * every provider has registered, so it never sees the raw FileLoader.
 */
final class VariantAwareLoader implements Loader
{
    public function __construct(
        private readonly Loader $inner,
        private readonly Locales $locales,
    ) {}

    public function inner(): Loader
    {
        return $this->inner;
    }

    /** @return array<array-key, mixed> */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group !== '*' || $namespace !== '*') {
            return $lines;
        }

        $chain = $this->locales->chain((string) $locale);
        // Bases only: the fallback at the end is the translator's own job.
        $bases = array_slice($chain, 1, -1);

        foreach ($bases as $base) {
            $lines = array_merge($this->inner->load($base, '*', '*'), $lines);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->inner->namespaces();
    }
}
