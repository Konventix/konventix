<?php

declare(strict_types=1);

namespace Konventix\Platform\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Constrains every query on a brand-owned model to the resolved brand.
 *
 * LanCore scoped by hand — 190 `event_id` references, 43 hand-written where
 * clauses, zero global scopes — and every forgotten clause was a leak between
 * events. Here it is a property of the persistence layer (ADR-0010).
 *
 * When no brand is in context the scope does not constrain. That is not a hole
 * in web requests: ResolveBrand aborts before the controller runs if the host
 * matches no brand, so a request always has one. It exists so console commands,
 * migrations, and operator tooling can work across brands.
 */
/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class BrandScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $brandId = app(BrandContext::class)->id();

        if ($brandId !== null) {
            $builder->where($model->qualifyColumn('brand_id'), $brandId);

            return;
        }

        // ADR-0029 hardening: on the operator admin host there is no brand,
        // and an unscoped query would span every brand by accident. Constrain
        // to nothing instead — admin code that legitimately spans brands says
        // so with the greppable withoutBrandScope() escape hatch (ADR-0010).
        if (app(OperatorContext::class)->hasOperator()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
