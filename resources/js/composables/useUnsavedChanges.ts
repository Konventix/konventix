import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Guards a form's dirty state against accidental loss: Inertia GET
 * navigations ask for confirmation while the form has unsaved changes,
 * and hard reloads / tab closes arm the browser's native leave prompt.
 *
 * Non-GET visits pass untouched — the form's own submit and sibling
 * posts (e.g. venue assignment) must never be blocked. Prefetch and
 * background (async) visits pass too: they load data without leaving
 * the page, so there is nothing to lose.
 *
 * Pass a getter so the current dirty state is read at navigation time:
 * `useUnsavedChanges(() => form.isDirty)`.
 */
export function useUnsavedChanges(isDirty: () => boolean): void {
    let removeBeforeListener: (() => void) | null = null;

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (isDirty()) {
            event.preventDefault();
        }
    }

    onMounted(() => {
        removeBeforeListener = router.on('before', (event) => {
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
        });

        window.addEventListener('beforeunload', onBeforeUnload);
    });

    onBeforeUnmount(() => {
        removeBeforeListener?.();
        window.removeEventListener('beforeunload', onBeforeUnload);
    });
}
