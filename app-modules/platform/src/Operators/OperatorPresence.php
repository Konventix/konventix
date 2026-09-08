<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Data\OperatorBrandLinkData;
use Konventix\Platform\Data\OperatorPresenceData;
use Konventix\Platform\Models\Brand;

/**
 * The operator behind the brand this host serves, and its other brands
 * (#184, ADR-0054) — what the auth pages need in order to say whose account
 * is being created or used.
 *
 * Null off a brand host. The operator console resolves no brand (ADR-0029)
 * and introduces nobody; sharing this there would also mean picking an
 * operator out of a request that deliberately has no brand in it.
 *
 * `BrandContext` is resolved at call time and never held: this is shared
 * from the Inertia middleware on every request, and a captured brand would
 * outlive the request under Octane (.ai/rules/providers.md).
 *
 * One query for the brands and one for their domains (`BrandUrl::basesFor`)
 * — an operator with a dozen brands must not cost a domain lookup each.
 */
final class OperatorPresence
{
    public function __construct(private readonly BrandUrl $brandUrl) {}

    public function current(): ?OperatorPresenceData
    {
        $brand = app(BrandContext::class)->current();

        if ($brand === null) {
            return null;
        }

        $operator = $brand->operator;

        if ($operator === null) {
            return null;
        }

        // Brand is the scoping level and is not itself brand-scoped (see its
        // docblock), so this is a plain query with no escape hatch to reach
        // for. Alphabetical so the list reads identically on every brand of
        // the operator.
        $brands = Brand::query()
            ->where('operator_id', $operator->getKey())
            ->orderBy('name')
            ->get();

        $bases = $this->brandUrl->basesFor($brands);

        return new OperatorPresenceData(
            name: $operator->name,
            legalName: $operator->legal_name,
            brands: array_values($brands
                ->map(fn (Brand $sibling): OperatorBrandLinkData => new OperatorBrandLinkData(
                    id: (string) $sibling->getKey(),
                    name: $sibling->name,
                    url: $bases[$sibling->getKey()] ?? null,
                    current: $sibling->getKey() === $brand->getKey(),
                ))
                ->all()),
        );
    }
}
