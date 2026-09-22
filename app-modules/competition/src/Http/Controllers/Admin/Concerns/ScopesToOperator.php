<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin\Concerns;

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Keeps a competition console to the admin host's own brands (ADR-0029).
 *
 * Every console query here escapes `BrandScope`, because the scope hardens to
 * zero rows on the admin host — which also means nothing *else* stops a
 * console account of operator Y that knows a ULID of operator X from reading
 * or rewriting X's tournament. The capability was checked against Y's team;
 * this is the check that the row is Y's at all.
 *
 * A row of another operator answers **404, never 403**: the console does not
 * confirm that somebody else's competition exists. The capability check stays
 * first in every method, so a console account without the capability still
 * reads 403 whatever it asks for. Same shape as Platform's
 * `BrandMenuAdminController`, Community's `ModerationController` and
 * Agenda's `AgendaAdminController`.
 */
trait ScopesToOperator
{
    /**
     * 404 unless the brand belongs to the operator whose admin host this is.
     */
    private function ensureOperatorOwns(string $brandId): void
    {
        abort_unless(in_array($brandId, $this->operatorBrandIds(), true), 404);
    }

    /**
     * The brands of the admin host's operator. A console request without a
     * resolved operator cannot reach here past `EnsureOperatorHost`, so its
     * absence is a 404 like any other stranger's request.
     *
     * Asked afresh on every call and never kept on `$this`: a controller
     * instance can outlive its request under Octane, and an operator's
     * brand list held on it would answer for the next request's operator.
     *
     * @return list<string>
     */
    private function operatorBrandIds(): array
    {
        $operator = app(OperatorContext::class)->current() ?? abort(404);

        return array_values(array_map(
            strval(...),
            Brand::query()->where('operator_id', $operator->getKey())->pluck('id')->all(),
        ));
    }

    /**
     * The brand picker, narrowed to the operator's own brands.
     *
     * @return list<array{id: string, name: string}>
     */
    private function operatorBrandOptions(): array
    {
        return array_values(Brand::query()
            ->whereIn('id', $this->operatorBrandIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
            ->all());
    }
}
