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
 * Re-arm every guard on the page: the document did not leave after all,
 * so unsaved changes are once again worth a prompt.
 *
 * Three moments say that, and `navigate` is only one of them — a release
 * that waits for it alone outlives the navigation it was meant for.
 * `pageshow` covers the browser handing a page back from the bfcache
 * (which fires *instead of* `navigate`, since nothing re-rendered), and
 * `onBeforeUnmount` covers a guard leaving the page it belonged to.
 */
export function rearmNavigationGuard(): void {
    navigationAllowed = false;
}

/**
 * The document is here after all — either it never left, or the browser
 * restored it from the bfcache, which renders no page and therefore fires
 * no `navigate`. The `pageshow` listener is this function.
 */
export function handlePageShow(): void {
    rearmNavigationGuard();
}

/**
 * A guard is leaving the page it belonged to. It must not hand a released
 * document to whatever mounts next; `onBeforeUnmount` is this function.
 */
export function releaseOnUnmount(): void {
    rearmNavigationGuard();
}

/**
 * Whether an `inertia:location` event actually means the document is about
 * to leave.
 *
 * Not every one does. Inertia fires `location` first and only then decides:
 * on an asset-version change during an `async` visit it returns without
 * touching `window.location` at all (`Response.locationVisit()`), so the
 * page stays exactly where it is — with the guard released and no
 * `navigate` coming to re-arm it. From then on the form silently lost its
 * unsaved changes on the next reload.
 *
 * The event carries `versionChange` but not `async`, so this errs towards
 * the prompt: a version-change location is treated as "may not leave", and
 * the worst case is one confirmation dialog on a redirect that does leave.
 * A dropped form is not recoverable; a dialog is.
 */
export function shouldReleaseForLocation(versionChange: boolean): boolean {
    return !versionChange;
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
            // what would otherwise trigger the leave prompt — except when
            // Inertia decides not to assign it after all, which is what
            // `shouldReleaseForLocation()` is about.
            router.on('location', (event) => {
                if (shouldReleaseForLocation(event.detail.versionChange)) {
                    allowNavigation();
                }
            }),
            // The page rendered instead of leaving — guard back on.
            router.on('navigate', () => {
                rearmNavigationGuard();
            }),
        );

        window.addEventListener('beforeunload', onBeforeUnload);
        window.addEventListener('pageshow', handlePageShow);
    });

    onBeforeUnmount(() => {
        removeListeners.forEach((remove) => remove());
        removeListeners.length = 0;
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.removeEventListener('pageshow', handlePageShow);

        releaseOnUnmount();
    });

    return { allowNavigation };
}
