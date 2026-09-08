<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Data\OperatorBrandLinkData;
use Konventix\Platform\Data\OperatorPresenceData;
use Konventix\Platform\Legal\PublicLegalTexts;
use Konventix\Platform\Models\Brand;

/**
 * The brand this host serves, the operator behind it, and its other brands
 * (#184, #241, ADR-0054) — what the auth pages need in order to say whose
 * account is being created or used, and where.
 *
 * Null off a brand host. The operator console resolves no brand (ADR-0029)
 * and introduces nobody; sharing this there would also mean picking an
 * operator out of a request that deliberately has no brand in it.
 *
 * **Resolved where it is shown, not on every request** (fix round 1). This
 * costs three queries and is read by exactly one component, on the six auth
 * pages; sharing it from the Inertia middleware charged the shop, the
 * landing page and every member page for a notice they never render. The
 * auth surfaces ask for it themselves through `props()`.
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

    /**
     * The page prop, ready to spread into an `Inertia::render()` on an auth
     * surface. One place decides the prop's name, so the component and every
     * caller cannot drift apart.
     *
     * @return array{operatorPresence: array<string, mixed>|null}
     */
    public function props(): array
    {
        return ['operatorPresence' => $this->current()?->toArray()];
    }

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
            // The brand the visitor is actually standing on, named beside
            // the operator rather than only flagged inside `brands` (#241).
            brandName: $brand->name,
            brands: array_values($brands
                ->map(fn (Brand $sibling): OperatorBrandLinkData => new OperatorBrandLinkData(
                    id: (string) $sibling->getKey(),
                    name: $sibling->name,
                    url: $bases[$sibling->getKey()] ?? null,
                    current: $sibling->getKey() === $brand->getKey(),
                ))
                ->all()),
            // Null where nothing is published: the registration text then
            // names the controller without linking to a page that 404s.
            // Compliance answers this through Platform's own contract, so
            // the kernel never names a `Policy` (ADR-0004).
            privacyPolicyUrl: app(PublicLegalTexts::class)->urlFor((string) $brand->getKey(), 'privacy'),
        );
    }
}
