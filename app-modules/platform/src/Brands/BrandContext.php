<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Konventix\Platform\Models\Brand;
use RuntimeException;

/**
 * The brand resolved for the current request, command, or queued job.
 *
 * Bound as a singleton. Middleware sets it from the request host (ADR-0001);
 * the BelongsToBrand global scope reads it (ADR-0010); the queue serializes it
 * so workers handle jobs in the same brand as the request that dispatched them.
 *
 * This is deliberately *not* multi-tenancy — there is no isolation layer, only
 * scoping. See docs/architecture/overview.md.
 */
class BrandContext
{
    private ?Brand $brand = null;

    public function set(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function forget(): void
    {
        $this->brand = null;
    }

    public function current(): ?Brand
    {
        return $this->brand;
    }

    public function id(): ?string
    {
        return $this->brand?->getKey();
    }

    public function hasBrand(): bool
    {
        return $this->brand instanceof Brand;
    }

    /**
     * The current brand, or a failure. Use in code that cannot meaningfully
     * continue without one, so the bug surfaces here rather than as a silently
     * unscoped query somewhere downstream.
     */
    public function currentOrFail(): Brand
    {
        return $this->brand ?? throw new RuntimeException(
            'No brand has been resolved for this context. Web requests resolve one in '
            .'ResolveBrand middleware; console commands and tests must set one explicitly.'
        );
    }

    /**
     * Run a callback with a different brand in context, restoring the previous
     * one afterwards. The named escape hatch for legitimate cross-brand work.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withBrand(Brand $brand, callable $callback): mixed
    {
        $previous = $this->brand;
        $this->brand = $brand;

        try {
            return $callback();
        } finally {
            $this->brand = $previous;
        }
    }
}
