import { ref } from 'vue';

/**
 * One shared open/closed state for the cookie-settings dialog (WP194 §3.6),
 * module-level rather than per-component: the footer's `cookie-settings`
 * link, the appearance tab's inline note and the operator console's own
 * trigger all open the same dialog instance instead of each holding a copy
 * that could disagree with the others.
 */
const isOpen = ref(false);

export function useCookieSettingsDialog() {
    function open(): void {
        isOpen.value = true;
    }

    function close(): void {
        isOpen.value = false;
    }

    return { isOpen, open, close };
}
