import { beforeEach, describe, expect, it, vi } from 'vitest';

const routerGet = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => routerGet(...args) },
}));

const { useDataTable } = await import('./useDataTable');

/**
 * A console list whose brand filter defaults to the operator's console
 * selection can be cleared per list, and the cleared state has to survive
 * every later navigation — `navigate()` drops nulls, so an absent `brand` is
 * indistinguishable from "never chose" and the server would hand the
 * selection back. The page therefore seeds the composable with the filter's
 * clear value (`all`), not with the null the server echoed.
 */
describe('useDataTable — a cleared selection-backed filter', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    function paramsOfLastVisit(): Record<string, string | number> {
        return routerGet.mock.calls.at(-1)?.[1] as Record<
            string,
            string | number
        >;
    }

    it('keeps carrying `brand=all` when the list is sorted', () => {
        const { toggleSort } = useDataTable(() => '/admin/orders', {
            brand: 'all',
            sort: 'created_at',
            dir: 'desc',
        });

        toggleSort('status');

        expect(paramsOfLastVisit()).toMatchObject({
            brand: 'all',
            sort: 'status',
        });
    });

    it('keeps carrying `brand=all` when another filter changes', () => {
        const { setFilter } = useDataTable(() => '/admin/registrations', {
            brand: 'all',
            event: 'all',
        });

        setFilter('status', 'approved');

        expect(paramsOfLastVisit()).toMatchObject({
            brand: 'all',
            event: 'all',
            status: 'approved',
        });
    });

    it('drops the filter entirely when it is cleared to null instead', () => {
        // The other half of the contract: a filter with no console default
        // (status) clears by disappearing, which is why the selection-backed
        // ones need a clear value of their own.
        const { setFilter } = useDataTable(() => '/admin/registrations', {
            status: 'approved',
        });

        setFilter('status', null);

        expect(paramsOfLastVisit()).not.toHaveProperty('status');
    });
});
