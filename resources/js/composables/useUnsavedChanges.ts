import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Is the document currently allowed to leave without the native leave
 * prompt, because the application itself asked for the navigation?
 *
 * Module scope on purpose: a page carries several guards at once — the
 * settlement console has three — and each hangs its own `beforeunload`
 * listener on the same window. A release that lived per composable
 * instance would silence the calling guard and leave the siblings
 * prompting for the very same navigation.
 */
let navigationAllowed = false;

/**
 * The pattern for a guarded form that ends in an external redirect
 * (Stripe Connect onboarding, a provider's hosted checkout, any other
 * deliberate jump out of the application): announce the jump, and the
 * native leave prompt stays quiet for it.
 *
 * A server-driven redirect needs no call at all. `Inertia::location()`
 * answers with 409 + `x-inertia-location`, and Inertia fires its
 * cancelable `location` event synchronously right before it assigns
 * `window.location.href` — every mounted guard listens for that and
 * releases itself. So the three `Inertia::location()` call sites are
 * covered without a line of page code, and a fourth one will be too.
 *
 * Call this by hand only for a navigation the page performs itself
 * (assigning `window.location`, submitting a plain `<form>` to another
 * origin), immediately before performing it.
 *
 * The release is deliberately *not* consumed by the unload prompt: all
 * guards on the page must see it, and a page may hold several. It ends
 * when Inertia renders a page again — a failed connect that comes back
 * with validation errors re-arms the guard with the typed values still
 * in the form.
 */
export function allowNavigation(): void {
    navigationAllowed = true;
}

/**
 * Re-arm every guard on the page. Called when Inertia renders a page:
 * the document did not leave after all, so unsaved changes are once
 * again worth a prompt.
 */
export function rearmNavigationGuard(): void {
    navigationAllowed = false;
}

/**
 * Whether `beforeunload` should raise the browser's leave prompt —
 * split out from the listener so the rule is testable without a DOM.
 */
export function shouldPreventUnload(isDirty: () => boolean): boolean {
    return !navigationAllowed && isDirty();
}

/**
 * Guards a form's dirty state against accidental loss: Inertia GET
 * navigations ask for confirmation while the form has unsaved changes,
 * and hard reloads / tab closes arm the browser's native leave prompt.
 *
 * Non-GET visits pass untouched — the form's own submit and sibling
 * posts (e.g. venue assignment) must never be blocked. Prefetch and
 * background (async) visits pass too: they load data without leaving
 * the page, so there is nothing to lose. A deliberate external redirect
 * passes as well; see `allowNavigation()` for that case.
 *
 * Pass a getter so the current dirty state is read at navigation time:
 * `useUnsavedChanges(() => form.isDirty)`.
 */
export function useUnsavedChanges(isDirty: () => boolean): {
    allowNavigation: () => void;
} {
    const removeListeners: (() => void)[] = [];

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (shouldPreventUnload(isDirty)) {
            event.preventDefault();
        }
    }

    onMounted(() => {
        removeListeners.push(
            router.on('before', (event) => {
                const visit = event.detail.visit;

                if (visit.method !== 'get' || visit.prefetch || visit.async) {
                    return true;
                }

                return (
                    !isDirty() ||
                    confirm(
                        'You have unsaved changes. Leave this page without saving?',
                    )
                );
            }),
            // The response to `Inertia::location()`: this fires immediately
            // before the hard `window.location.href` assignment, which is
            // what would otherwise trigger the leave prompt.
            router.on('location', () => {
                allowNavigation();
            }),
            // The page rendered instead of leaving — guard back on.
            router.on('navigate', () => {
                rearmNavigationGuard();
            }),
        );

        window.addEventListener('beforeunload', onBeforeUnload);
    });

    onBeforeUnmount(() => {
        removeListeners.forEach((remove) => remove());
        removeListeners.length = 0;
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    return { allowNavigation };
}
