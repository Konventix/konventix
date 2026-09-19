<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import TicketPageController from '@/actions/Konventix/Admission/Http/Controllers/TicketPageController';
import Heading from '@/components/Heading.vue';

/**
 * "What can I get into" — the holder's own tickets, reached from the
 * account menu (design §7), and beneath them the tickets this person
 * bought for others (#292 §4.5): a manager who holds none of their group's
 * tickets still has an entry point. Every row opens the ticket page; the
 * PDF lives there, not here, since the page is where the scan code, the
 * seat and the calendar file are too.
 */
type Ticket = Konventix.Admission.Data.MyTicketData;

const props = defineProps<{
    tickets: Ticket[];
    managed: Ticket[];
}>();
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
                <Link
                    :href="
                        TicketPageController.show.url({ admission: ticket.id })
                    "
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-medium text-text">
                            {{ ticket.eventName }}
                        </span>
                        <span class="flex flex-wrap items-center gap-2 pt-1">
                            <span
                                class="rounded-full bg-subtle px-2 py-0.5 text-xs font-medium text-text capitalize"
                            >
                                {{ ticket.role }}
                            </span>
                        </span>
                    </span>

                    <ChevronRight
                        class="size-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ul>

        <section
            v-if="props.managed.length > 0"
            class="mt-10 flex flex-col gap-3"
            aria-labelledby="managed-tickets"
        >
            <h2 id="managed-tickets" class="text-lg font-medium">
                Tickets you manage
            </h2>
            <p class="text-sm text-text-muted">
                Bought by you, held by somebody else or nobody yet. Assign them
                from the registration they belong to.
            </p>
            <ul class="flex flex-col gap-3">
                <li v-for="ticket in props.managed" :key="ticket.id">
                    <Link
                        :href="
                            TicketPageController.show.url({
                                admission: ticket.id,
                            })
                        "
                        class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                    >
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="font-medium text-text">
                                {{ ticket.eventName }}
                            </span>
                            <span class="text-sm text-text-muted">
                                {{ ticket.holderName ?? 'Not yet assigned' }}
                                ·
                                <span class="capitalize">{{
                                    ticket.role
                                }}</span>
                            </span>
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
