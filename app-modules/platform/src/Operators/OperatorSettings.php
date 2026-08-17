<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Konventix\Platform\Data\OperatorSellerData;
use Konventix\Platform\Models\Brand;
use RuntimeException;

/**
 * Read-side access to operator-level settlement identity for other modules.
 *
 * Commerce needs the operator's legal identity (invoice seller) and its
 * §19 UStG election (ADR-0030) but may not import the Operator Eloquent
 * model — the cross-module allow-list covers only `Brand`, `Role`,
 * `Permission` and the `Models\Concerns` traits. This service is the
 * sanctioned doorway: plain-scalar Data out, no model leaks.
 *
 * Stateless — safe as a plain container resolution under Octane.
 */
class OperatorSettings
{
    public function sellerForBrand(string $brandId): OperatorSellerData
    {
        $brand = Brand::query()->findOrFail($brandId);

        $operator = $brand->operator;

        if ($operator === null) {
            throw new RuntimeException(
                "Brand [{$brandId}] has no operator; an invoice cannot be issued without a legal seller identity. "
                .'Backfill brands.operator_id (see the seeder) before settling money on this brand.'
            );
        }

        return OperatorSellerData::fromModel($operator);
    }

    public function currencyForBrand(string $brandId): string
    {
        return Brand::query()->findOrFail($brandId)->currency;
    }

    /**
     * Where operator-facing mail goes for this brand — Compliance's deadline
     * escalation is the first caller.
     *
     * One address rather than "everyone holding the capability": a statutory
     * deadline needs an owner, and a mail to fifteen people is a mail nobody
     * answers. Null when the brand has no operator or the operator left the
     * field empty, and the caller then has nowhere to escalate to (which the
     * scheduler logs rather than guessing).
     */
    public function contactEmailForBrand(string $brandId): ?string
    {
        $operator = Brand::query()->find($brandId)?->operator;

        return $operator?->contact_email;
    }
}
