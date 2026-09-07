import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: () => () => {} },
}));

const {
    allowNavigation,
    handlePageShow,
    rearmNavigationGuard,
    releaseOnUnmount,
    shouldPreventUnload,
    shouldReleaseForLocation,
} = await import('./useUnsavedChanges');

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

    it('is armed again when the browser hands the page back from the bfcache', () => {
        // `pageshow` is the only signal there: nothing re-rendered, so
        // Inertia fires no `navigate`, and a release that waits for one
        // outlives the navigation it was meant for. This is the function
        // the composable registers as the `pageshow` listener.
        allowNavigation();
        handlePageShow();

        expect(shouldPreventUnload(dirty)).toBe(true);
    });

    it('is armed again when a guard leaves the page it belonged to', () => {
        // The function `onBeforeUnmount` calls: a released document must
        // not be handed to whatever mounts next.
        allowNavigation();
        releaseOnUnmount();

        expect(shouldPreventUnload(dirty)).toBe(true);
    });
});

/**
 * `inertia:location` fires before Inertia decides whether to assign
 * `window.location` at all: on an asset-version change during an `async`
 * visit it returns without navigating (`Response.locationVisit()`). The
 * guard used to be released for that one too, and nothing re-armed it —
 * the page had not moved, so no `navigate` and no `pageshow` followed.
 */
describe('useUnsavedChanges — the location rule', () => {
    beforeEach(() => {
        rearmNavigationGuard();
    });

    it('releases for a location visit that really does leave', () => {
        expect(shouldReleaseForLocation(false)).toBe(true);
    });

    it('keeps the guard armed for a version-change location, which may not leave', () => {
        expect(shouldReleaseForLocation(true)).toBe(false);
    });
});
