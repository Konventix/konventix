import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive } from 'vue';

/**
 * Server-side data-table filter state for operator-console index pages
 * (ADR-0029). Query-param convention: `search`, `sort`, `dir`, `per_page` —
 * mirrored on the backend by `Konventix\Platform\Http\Concerns\HasDataTableQuery`.
 * Ported concept from LanCore's `useDataTable` composable.
 */
export interface DataTableFilters {
    search?: string;
    sort?: string;
    dir?: 'asc' | 'desc';
    per_page?: number;
    [key: string]: string | number | undefined;
}

export function useDataTable(
    routeUrl: () => string,
    initialFilters: DataTableFilters = {},
) {
    const filters = reactive<DataTableFilters>({ ...initialFilters });

    function navigate(overrides: DataTableFilters = {}): void {
        const params: Record<string, string | number> = {};

        for (const [key, value] of Object.entries({
            ...filters,
            ...overrides,
        })) {
            if (value !== undefined && value !== '' && value !== null) {
                params[key] = value;
            }
        }

        router.get(routeUrl(), params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    const debouncedSearch = useDebounceFn(
        () => navigate({ page: undefined }),
        300,
    );

    function setSearch(value: string): void {
        filters.search = value || undefined;
        debouncedSearch();
    }

    function toggleSort(column: string): void {
        if (filters.sort === column) {
            filters.dir = filters.dir === 'asc' ? 'desc' : 'asc';
        } else {
            filters.sort = column;
            filters.dir = 'asc';
        }

        navigate({ page: undefined });
    }

    function setPerPage(perPage: number): void {
        filters.per_page = perPage;
        navigate({ page: undefined });
    }

    function setPage(page: number): void {
        navigate({ page });
    }

    return {
        filters,
        setSearch,
        toggleSort,
        setPerPage,
        setPage,
        navigate,
    };
}
