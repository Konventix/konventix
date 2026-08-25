import { beforeEach, describe, expect, it, vi } from 'vitest';

const routerGet = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => routerGet(...args) },
}));

const { seedSelectionFilter, useDataTable } = await import('./useDataTable');

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

    it('carries what `seedSelectionFilter` produces for a shell with a pick', () => {
        const { toggleSort } = useDataTable(() => '/admin/orders', {
            // The server echoed null for a brand the operator cleared, and
            // the shell still holds the selection it was cleared against.
            brand: seedSelectionFilter(null, '01k-sxlan'),
        });

        toggleSort('status');

        expect(paramsOfLastVisit()).toMatchObject({ brand: 'all' });
    });

    it('does not pin an echoed brand that is merely the shell’s own pick', () => {
        // The server echoed the selection because the parameter was absent,
        // not because anyone chose it here. Pinning it would let a later
        // pick in the shell land on a URL that overrules it.
        const { toggleSort } = useDataTable(() => '/admin/orders', {
            brand: seedSelectionFilter('01k-sxlan', '01k-sxlan'),
        });

        toggleSort('status');

        expect(paramsOfLastVisit()).not.toHaveProperty('brand');
    });

    it('keeps an echoed brand the operator chose against the shell', () => {
        const { toggleSort } = useDataTable(() => '/admin/orders', {
            brand: seedSelectionFilter('01k-xpertise', '01k-sxlan'),
        });

        toggleSort('status');

        expect(paramsOfLastVisit()).toMatchObject({ brand: '01k-xpertise' });
    });

    it('sends no brand at all when the shell has nothing selected', () => {
        // Nothing to clear, so nothing to pin: a brand picked in the shell
        // later must still reach this list.
        const { toggleSort } = useDataTable(() => '/admin/orders', {
            brand: seedSelectionFilter(null, null),
        });

        toggleSort('status');

        expect(paramsOfLastVisit()).not.toHaveProperty('brand');
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
