<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Shared server-side data-table query handling for operator-console index
 * endpoints (ADR-0029): `search`, `sort`, `dir`, `per_page` query params,
 * mirrored by the frontend `useDataTable` composable.
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
     * @param  array{column: string, direction: 'asc'|'desc'}  $sort
     * @return array{search: string|null, sort: string, dir: string, per_page: int}
     */
    protected function currentFilters(Request $request, array $sort, int $perPage): array
    {
        return [
            'search' => $request->filled('search') ? $request->string('search')->toString() : null,
            'sort' => $sort['column'],
            'dir' => $sort['direction'],
            'per_page' => $perPage,
        ];
    }
}
