import { ref } from 'vue';

/**
 * One shared open/closed state for the report dialog, module-level rather
 * than per component — the same shape `useCookieSettingsDialog` uses, and
 * for the same reason: the launcher opens it today, and design §16's "ask
 * about this" slot will open the *same* dialog from an order page
 * tomorrow, with a typed reference attached.
 *
 * `reference` is that reservation, filled by `openWithReference()` and
 * posted as `subject_module`/`subject_id` once the slot mechanism exists.
 * Nothing sets it yet; it is here so the slot is a caller change rather
 * than a dialog change.
 */
export interface SupportSubjectReference {
    module: string;
    id: string;
}

const isOpen = ref(false);
const reference = ref<SupportSubjectReference | null>(null);

export function useSupportDialog() {
    function open(): void {
        reference.value = null;
        isOpen.value = true;
    }

    function openWithReference(module: string, id: string): void {
        reference.value = { module, id };
        isOpen.value = true;
    }

    function close(): void {
        isOpen.value = false;
    }

    return { isOpen, reference, open, openWithReference, close };
}
