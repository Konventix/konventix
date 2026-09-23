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
    /** accessible label, also the "all" option's text: `$t('All :label', { label })` — pass it translated and as a plural noun */
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
 * The seed value for a filter whose default is the console selection — the
 * one place the whole selection/filter contract is stated.
 *
 * The server's half (`HasDataTableQuery::resolveSelectionFilter`): an absent
 * parameter means "never chose" and takes the shell's selection; the literal
 * `all` means the operator cleared this list; anything else is that value.
 * `navigate()` omits `undefined` and `null` alike, so whatever this function
 * returns decides what the next search, sort or page carries — and therefore
 * which of those three the server sees next time.
 *
 * The four arms, and why each is what it is:
 *
 * - echoed value ≠ the current selection → keep it. The operator chose this
 *   brand in the list's own toolbar (or arrived on a bookmarked link), and
 *   that choice has to survive the next sort instead of snapping back.
 * - echoed value = the current selection → seed nothing. The server only
 *   handed this back because the parameter was absent and it filled in the
 *   selection; it is not a choice, and pinning it into the URL would make a
 *   later pick in the shell look ignored — the shell redirects back to this
 *   list, the explicit parameter would win, and the console's brand selector
 *   would appear to do nothing on this page.
 * - `null` with a selection behind it → `all`. The operator cleared the
 *   filter ("all brands", a legitimate console view); seeding nothing would
 *   drop the parameter and hand the selection straight back on the next
 *   visit, so the clearing has to keep being said out loud.
 * - `null` with nothing behind it → nothing. There was no selection to
 *   clear, so there is nothing to pin, and a brand picked in the shell later
 *   must still reach this list.
 */
export function seedSelectionFilter(
    echoed: string | null,
    selected: string | null | undefined,
): string | undefined {
    if (echoed !== null) {
        return echoed === selected ? undefined : echoed;
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
