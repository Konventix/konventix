<?php

declare(strict_types=1);

namespace Konventix\Platform\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * Every brand-owned model uses this trait (ADR-0010).
 *
 * It does two things: constrains all queries to the resolved brand, and stamps
 * `brand_id` on create. Writing `where('brand_id', ...)` as the scoping
 * mechanism is forbidden — that is the failure mode this replaces.
 *
 * The model's table needs a `brand_id` ulid column; see the `brands` migration
 * for the column definition to copy.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope(new BrandScope);

        static::creating(function ($model): void {
            if ($model->brand_id === null) {
                $model->brand_id = app(BrandContext::class)->currentOrFail()->getKey();
            }
        });
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * A brand *filter* over a query that has already escaped the brand
     * scope — the operator console narrowing a cross-brand list to one
     * brand the user chose (ADR-0015: reads are filters). Not a scoping
     * mechanism: the request-time BrandScope is, and ADR-0010 forbids
     * hand-written `where('brand_id', …)` for that job. Greppable for the
     * same reason `withoutBrandScope()` is.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBrand(Builder $query, string $brandId): Builder
    {
        return $query->where($query->qualifyColumn('brand_id'), $brandId);
    }

    /**
     * The named, greppable escape hatch for deliberate cross-brand queries.
     * Callers are responsible for checking an operator-level permission first;
     * if you are reaching for this inside a normal request, you have a bug.
     *
     * @return Builder<static>
     */
    public static function withoutBrandScope(): Builder
    {
        return static::query()->withoutGlobalScope(BrandScope::class);
    }
}
