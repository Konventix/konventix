<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileDown } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';

/**
 * "What can I get into" — the holder's own tickets, reached from the
 * account menu (design §4.1, §7). An admission nobody has claimed through
 * the existing claim links never appears here for anybody — its ticket
 * reaches its buyer through the buyer's own combined file instead.
 *
 * Each row downloads on its own: there is no detail page between the list
 * and the PDF, so the whole row is the download link — a plain anchor, not
 * an Inertia `<Link>`, the same reasoning `RegistrationSettlement.vue`'s
 * invoice download follows (it streams a PDF; an Inertia visit expects a
 * page component back).
 */
type Ticket = Konventix.Admission.Data.MyTicketData;

const props = defineProps<{
    tickets: Ticket[];
}>();

const stateTone: Record<string, string> = {
    issued: 'bg-success/15 text-success',
    assigned: 'bg-success/15 text-success',
    expired: 'bg-subtle text-text-muted',
};
</script>

<template>
    <Head title="My tickets" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            title="My tickets"
            description="Every admission claimed under your account"
        />

        <p v-if="props.tickets.length === 0" class="text-sm text-text-muted">
            No ticket has been claimed to your account yet.
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="ticket in props.tickets" :key="ticket.id">
                <a
                    :href="TicketDownloadController.holder.url(ticket.id)"
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-medium text-text">
                            {{ ticket.eventName }}
                        </span>
                        <span class="flex flex-wrap items-center gap-2 pt-1">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="
                                    stateTone[ticket.state] ??
                                    'bg-subtle text-text'
                                "
                            >
                                {{ ticket.role }}
                            </span>
                        </span>
                    </span>

                    <FileDown
                        class="size-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                </a>
            </li>
        </ul>
    </div>
</template>
