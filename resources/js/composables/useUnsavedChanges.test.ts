import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: () => () => {} },
}));

const { allowNavigation, rearmNavigationGuard, shouldPreventUnload } =
    await import('./useUnsavedChanges');

/**
 * The rule behind the `beforeunload` listener (ADR-0038). Clicking
 * "Connect" on the settlement console posts to a controller that answers
 * with `Inertia::location()`, and Inertia turns that into a real document
 * navigation — which used to meet an armed guard and ask "Leave this
 * page?" about the very step the operator asked for. The release is
 * page-wide because that page carries three guards at once.
 */
describe('useUnsavedChanges — the unload rule', () => {
    beforeEach(() => {
        rearmNavigationGuard();
    });

    const dirty = (): boolean => true;
    const clean = (): boolean => false;

    it('prompts while the form has unsaved changes', () => {
        expect(shouldPreventUnload(dirty)).toBe(true);
    });

    it('stays quiet for a clean form', () => {
        expect(shouldPreventUnload(clean)).toBe(false);
    });

    it('lets a deliberate external redirect leave without a prompt', () => {
        allowNavigation();

        expect(shouldPreventUnload(dirty)).toBe(false);
    });

    it('releases every guard on the page, not only the one that asked', () => {
        allowNavigation();

        // Three sibling guards, three dirty getters, one document.
        expect(shouldPreventUnload(dirty)).toBe(false);
        expect(shouldPreventUnload(dirty)).toBe(false);
        expect(shouldPreventUnload(dirty)).toBe(false);
    });

    it('is armed again once Inertia renders a page instead of leaving', () => {
        allowNavigation();
        rearmNavigationGuard();

        expect(shouldPreventUnload(dirty)).toBe(true);
    });
});
