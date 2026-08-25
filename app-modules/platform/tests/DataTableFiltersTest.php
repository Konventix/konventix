<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;

/**
 * The one filter contract every operator-console list reads (ADR-0029):
 * a controller declares its filters and their allowed values; unknown
 * values are dropped rather than passed to the query.
 */
final class DataTableFiltersProbe
{
    use HasDataTableQuery;

    /** @return array<string, string|null> */
    public function filters(Request $request): array
    {
        return $this->resolveFilters($request, ['status' => ['open', 'closed'], 'brand' => null]);
    }

    /** @return array<string, mixed> */
    public function current(Request $request): array
    {
        $sort = $this->resolveSort($request, ['name'], 'name');

        return $this->currentFilters($request, $sort, 15, ['status' => 'open']);
    }
}

it('keeps an allowed value and drops one the controller did not declare', function (): void {
    $probe = new DataTableFiltersProbe;

    expect($probe->filters(Request::create('/', 'GET', ['status' => 'open'])))
        ->toBe(['status' => 'open', 'brand' => null])
        ->and($probe->filters(Request::create('/', 'GET', ['status' => 'bogus'])))
        ->toBe(['status' => null, 'brand' => null]);
});

it('accepts any non-empty string for a filter declared without a value list', function (): void {
    $probe = new DataTableFiltersProbe;

    expect($probe->filters(Request::create('/', 'GET', ['brand' => '01JZZZZZZZZZZZZZZZZZZZZZZ1'])))
        ->toBe(['status' => null, 'brand' => '01JZZZZZZZZZZZZZZZZZZZZZZ1'])
        ->and($probe->filters(Request::create('/', 'GET', ['brand' => ''])))
        ->toBe(['status' => null, 'brand' => null]);
});

it('merges extra filters into the current-filters payload the page echoes back', function (): void {
    $probe = new DataTableFiltersProbe;

    expect($probe->current(Request::create('/', 'GET', ['search' => 'x'])))
        ->toBe(['search' => 'x', 'sort' => 'name', 'dir' => 'asc', 'per_page' => 15, 'status' => 'open']);
});
