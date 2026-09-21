<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { LifeBuoy } from '@lucide/vue';
import { computed } from 'vue';
import { useCookieSettingsDialog } from '@/composables/useCookieSettingsDialog';
import adminSupportRequests from '@/routes/admin/support/requests';
import supportRequests from '@/routes/support/requests';
import SupportRequestDialog from './SupportRequestDialog.vue';
import { useSupportContext } from './useSupportContext';
import { useSupportDialog } from './useSupportDialog';

/**
 * The floating button that carries the whole feature (design §6.1,
 * ADR-0063): bottom-right, on every brand host page *and* in the operator
 * console, because the people who find the bugs are on both.
 *
 * Mounted by the two shells through `resolveRegisteredComponent`, the same
 * way `AdminLayout` already mounts the cookie dialog — so a shell knows the
 * name `support/SupportLauncher` and nothing else about this module.
 *
 * **The host decides the endpoint, not the dialog.** `props.admin` is
 * non-null only on the operator console (`HandleInertiaRequests`), and that
 * is the one fact that picks between the two routes of §6.4. Resolving it
 * here keeps the Wayfinder import in the one component that is allowed to
 * know about hosts.
 *
 * Accessibility (§12, ADR-0055): a real `<button>`, so Enter and Space work
 * without help; a visible focus ring; an accessible name from the visually
 * hidden label on narrow viewports and from the visible one above 640 px;
 * `aria-haspopup="dialog"` and `aria-expanded` so the relationship to the
 * dialog is announced. The target is at least 44 px in both dimensions at
 * every width. No slide-in, so there is nothing for `prefers-reduced-motion`
 * to have to switch off.
 *
 * It hides itself while the cookie-settings dialog is open — two floating
 * surfaces in one corner is one too many — and on print, where a button is
 * ink and nothing else. (Design §6.1 says "while the cookie *banner* is
 * open"; this application has no banner, only the settings dialog the
 * footer and the console open, so that is what is honoured here.)
 */
const page = usePage();
const dialog = useSupportDialog();
const cookieSettings = useCookieSettingsDialog();

// Installs the visit ring buffer and the error handlers once per page load
// (§6.3). Done here rather than in the dialog: the buffer has to be
// recording long before anybody opens anything.
const context = useSupportContext();

const isConsole = computed(() => page.props.admin != null);

const endpoint = computed(() =>
    isConsole.value
        ? adminSupportRequests.store.url()
        : supportRequests.store.url(),
);

const isGuest = computed(() => page.props.auth?.user == null);
</script>

<template>
    <div v-if="!cookieSettings.isOpen.value">
        <button
            type="button"
            class="fixed right-4 bottom-4 z-40 inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-full border border-border bg-surface-raised px-3 text-text shadow-lg transition-colors hover:bg-subtle focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none print:hidden"
            :aria-expanded="dialog.isOpen.value"
            aria-haspopup="dialog"
            aria-label="Report a problem or give feedback"
            @click="dialog.open()"
        >
            <LifeBuoy class="size-5" aria-hidden="true" />
            <span class="hidden sm:inline">Feedback</span>
        </button>

        <SupportRequestDialog
            :open="dialog.isOpen.value"
            :endpoint="endpoint"
            :is-guest="isGuest"
            :context="context.snapshot"
            @close="dialog.close()"
        />
    </div>
</template>
