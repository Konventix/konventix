<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * A brand-owned model whose `brand_id` may deliberately be null, meaning
 * "the operator's, inherited by every brand" (GitLab #76, ADR-0016).
 *
 * `BelongsToBrand` stamps the resolved brand onto any row created with a null
 * `brand_id`, which is exactly right for rows that must belong to a brand and
 * exactly wrong for a text the operator writes once for all of them. It
 * cannot tell the two cases apart, so the model that has both says so by
 * overriding the trait's boot method with this one:
 *
 *     public static function bootBelongsToBrand(): void
 *     {
 *         static::bootOperatorWideBrandScope();
 *     }
 *
 * The global `BrandScope` is added unchanged — an operator-wide row is
 * invisible to a scoped query, and every read path that wants the fallback
 * escapes the scope deliberately and says why (ADR-0010).
 *
 * @phpstan-require-extends Model
 */
trait MayBeOperatorWide
{
    public static function bootOperatorWideBrandScope(): void
    {
        static::addGlobalScope(new BrandScope);

        static::creating(function (Model $model): void {
            // `array_key_exists`, not a null check: writing `brand_id => null`
            // is how a caller asks for an operator-wide row, and the absence
            // of the key is how a brand-owned row asks to be stamped. A null
            // check cannot distinguish "operator-wide" from "forgot the
            // brand", and the second must still fail loudly.
            if (array_key_exists('brand_id', $model->getAttributes())) {
                return;
            }

            $model->setAttribute('brand_id', app(BrandContext::class)->currentOrFail()->getKey());
        });
    }

    public function isOperatorWide(): bool
    {
        return $this->getAttribute('brand_id') === null;
    }
}
