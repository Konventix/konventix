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
    /**
     * `null` is in the union because that is what the server echoes for a
     * filter that is not filtering; `navigate()` leaves both null and
     * undefined out of the query string.
     */
    [key: string]: string | number | null | undefined;
}

export interface DataTableFilterOption {
    value: string;
    label: string;
}

/**
 * One declared filter, as `HasDataTableQuery::resolveFilters()` declares it
 * on the backend: the toolbar renders this and nothing else, so a filter
 * exists in exactly one place per list.
 */
export interface DataTableFilterDef {
    /** query-param name */
    name: string;
    /** accessible label, also the "all" option's text: `All ${label.toLowerCase()}` */
    label: string;
    /** current value; null = not filtering */
    value: string | null;
    options: DataTableFilterOption[];
    /**
     * sent instead of null when the user picks "All" — for filters whose
     * absence means "use the console default"
     */
    clearValue?: string;
}

/**
 * The seed value for a filter whose default is the console selection.
 *
 * Two things have to be told apart, and the server echoes `null` for both:
 * the operator cleared this list ("all brands", a legitimate console view),
 * and the shell simply has no selection to default from. `navigate()` omits
 * nulls, so seeding null would drop the parameter on the next search or sort
 * — and an absent `brand` means "never chose", which hands the selection
 * back. Seeding the clear value fixes that, but only the first case is a
 * clearing: there is nothing to clear when the shell has no pick, and
 * pinning `all` there would make a later pick in the shell look ignored.
 *
 * So: an echoed value stands; a null with a selection behind it is a
 * clearing and keeps carrying `all`; a null with nothing behind it is simply
 * absent.
 */
export function seedSelectionFilter(
    echoed: string | null,
    selected: string | null | undefined,
): string | undefined {
    if (echoed !== null) {
        return echoed;
    }

    return selected ? 'all' : undefined;
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

    function setFilter(name: string, value: string | null): void {
        filters[name] = value ?? undefined;
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
        setFilter,
        toggleSort,
        setPerPage,
        setPage,
        navigate,
    };
}
