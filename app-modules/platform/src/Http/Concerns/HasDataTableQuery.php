<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Concerns;

use Illuminate\Http\Request;
use Konventix\Platform\Models\Brand;

/**
 * Shared server-side data-table query handling for operator-console index
 * endpoints (ADR-0029): `search`, `sort`, `dir`, `per_page` query params
 * plus declared filters, mirrored by the frontend `useDataTable` composable.
 *
 * Column allowlists stay per-controller — never sort on a column the
 * controller didn't explicitly allow.
 */
trait HasDataTableQuery
{
    /**
     * @param  array<int, string>  $allowedSorts
     * @return array{column: string, direction: 'asc'|'desc'}
     */
    protected function resolveSort(Request $request, array $allowedSorts, string $default): array
    {
        $column = $request->string('sort')->toString();

        if (! in_array($column, $allowedSorts, true)) {
            $column = $default;
        }

        $direction = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';

        return ['column' => $column, 'direction' => $direction];
    }

    protected function resolvePerPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = $request->integer('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Declared filters and their current values. A controller names each
     * filter it honours and, where the values are a closed set, the values
     * it accepts; anything else in the query string is dropped here rather
     * than reaching the query. The same declaration is what the toolbar
     * renders, so a filter exists in exactly one place.
     *
     * @param  array<string, list<string>|null>  $allowed  filter name => allowed values, or null for any non-empty string
     * @return array<string, string|null>
     */
    protected function resolveFilters(Request $request, array $allowed): array
    {
        $resolved = [];

        foreach ($allowed as $name => $values) {
            $value = $request->string($name)->toString();

            if ($value === '' || ($values !== null && ! in_array($value, $values, true))) {
                $resolved[$name] = null;

                continue;
            }

            $resolved[$name] = $value;
        }

        return $resolved;
    }

    /**
     * A filter whose default is the console selection (ADR-0029): absent
     * from the query string → the selection; the literal `all` → no filter,
     * because the user cleared it for this list; anything else → that value.
     *
     * The selection is a default, never a scope — "all brands" is a
     * legitimate view of an operator console, so a cleared filter has to
     * survive the next visit, which is why clearing sends `all` rather than
     * dropping the parameter.
     */
    protected function resolveSelectionFilter(Request $request, string $name, ?string $selected): ?string
    {
        if (! $request->has($name)) {
            return $selected;
        }

        $value = $request->string($name)->toString();

        return ($value === '' || $value === 'all') ? null : $value;
    }

    /**
     * The brand filter's options, for a list whose brand filter defaults
     * from the console selection. Every such list offers the same set — the
     * operator's brands, by name — so it is stated once here rather than
     * copied into each controller.
     *
     * @return list<array{id: string, name: string}>
     */
    protected function brandOptions(): array
    {
        return array_values(Brand::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
            ->all());
    }

    /**
     * The query state the page echoes back to `useDataTable`: always
     * `search` (string|null), `sort` (string), `dir` (string) and
     * `per_page` (int), plus whatever `$extra` carries — normally the
     * `resolveFilters()` result, merged last. The declared shape stays
     * prose because `$extra` widens it per caller.
     *
     * @param  array{column: string, direction: 'asc'|'desc'}  $sort
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function currentFilters(Request $request, array $sort, int $perPage, array $extra = []): array
    {
        return [
            'search' => $request->filled('search') ? $request->string('search')->toString() : null,
            'sort' => $sort['column'],
            'dir' => $sort['direction'],
            'per_page' => $perPage,
            ...$extra,
        ];
    }
}
