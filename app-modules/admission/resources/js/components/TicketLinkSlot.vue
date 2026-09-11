<script setup lang="ts">
import { Ticket as TicketIcon } from '@lucide/vue';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';

/**
 * Admission's contribution to Registration's account page (design §7),
 * registered by `AdmissionServiceProvider::registerSlots()` on
 * `registration.mine.show`: how many tickets the buyer's file holds, and
 * the link to download it. `ticket` is null for a registration that
 * granted no admissions, or whose admissions have all been revoked since —
 * the same `null`-means-render-nothing idiom `SeatSummarySlot.vue` uses on
 * this same slot.
 *
 * The link is a plain anchor, not an Inertia `<Link>`: it streams a PDF,
 * and an Inertia visit would expect a page component back — the same
 * reasoning `RegistrationSettlement.vue`'s invoice download follows.
 */
type TicketLink = Konventix.Admission.Data.TicketLinkData;

const props = defineProps<{
    ticket: TicketLink | null;
}>();
</script>

<template>
    <section
        v-if="props.ticket"
        class="flex flex-col gap-3"
        aria-labelledby="registration-tickets"
    >
        <h2 id="registration-tickets" class="text-lg font-medium">
            {{ props.ticket.ticketCount === 1 ? 'Ticket' : 'Tickets' }}
        </h2>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-raised p-4"
        >
            <span class="text-sm text-text-muted">
                {{
                    props.ticket.ticketCount === 1
                        ? 'Your ticket is ready to download.'
                        : `Your ${props.ticket.ticketCount} tickets are ready to download.`
                }}
            </span>
            <span class="flex-1" />
            <a
                :href="
                    TicketDownloadController.buyer.url(
                        props.ticket.registrationId,
                    )
                "
                class="inline-flex min-h-11 items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
            >
                <TicketIcon class="size-4" />
                Download
                {{ props.ticket.ticketCount === 1 ? 'ticket' : 'tickets' }}
                (PDF)
            </a>
        </div>
    </section>
</template>
