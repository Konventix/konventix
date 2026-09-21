<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ticket as TicketIcon } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { show as showEvent } from '@/routes/event';

/**
 * What an invitee is about to claim (#292 §4.5) — the event, the role, who
 * invited them and when the offer closes — before the one button that
 * takes it. The POST goes to the page's own signed URL: the signature
 * covers path and query, never the method, so the same link serves both.
 *
 * A bound link names the address it is meant for so a person signed in
 * under another one understands the 410 they are about to get rather than
 * guessing. `alreadyHolderOfAnother` only informs: two tickets for one
 * person is the manager's call. The inviter is a handle (ADR-0065) and
 * the event links to its page when the summary knows the slug.
 */
// Spelled out rather than `Konventix.Admission.Data.ClaimLandingData`: a
// bare namespaced type fails the SFC compiler's macro type resolver (see
// `seating/Pick.vue`). Mirrors `ClaimLandingData`.
type Props = {
    linkId: string;
    eventName: string | null;
    eventSlug: string | null;
    role: string;
    managerUsername: string | null;
    intendedEmail: string | null;
    expiresAt: string;
    alreadyHolderOfAnother: boolean;
    claimUrl: string;
};

const props = defineProps<Props>();

const claiming = ref(false);

function claim(): void {
    if (claiming.value) {
        return;
    }

    claiming.value = true;
    router.post(
        props.claimUrl,
        {},
        {
            onFinish: () => {
                claiming.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Claim your ticket" />

    <div class="mx-auto w-full max-w-xl px-4 py-6">
        <Heading
            as="h1"
            title="A ticket is waiting for you"
            :description="
                props.managerUsername
                    ? `@${props.managerUsername} has reserved it for you.`
                    : 'It has been reserved for you.'
            "
        />

        <dl
            class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 rounded-xl border border-border bg-surface-raised p-4 text-sm"
        >
            <dt class="text-text-muted">Event</dt>
            <dd class="font-medium text-text">
                <Link
                    v-if="props.eventSlug"
                    :href="showEvent(props.eventSlug).url"
                    class="hover:text-accent"
                >
                    {{ props.eventName ?? 'This event' }}
                </Link>
                <template v-else>{{
                    props.eventName ?? 'This event'
                }}</template>
            </dd>
            <dt class="text-text-muted">Ticket</dt>
            <dd class="capitalize">{{ props.role }}</dd>
            <template v-if="props.intendedEmail">
                <dt class="text-text-muted">Meant for</dt>
                <dd>{{ props.intendedEmail }}</dd>
            </template>
            <dt class="text-text-muted">Offer closes</dt>
            <!-- datetime-raw: props.expiresAt — already formatted on the
                 server in the brand's zone, the way the invitation mail
                 printed it. -->
            <dd>{{ props.expiresAt }}</dd>
        </dl>

        <p
            v-if="props.alreadyHolderOfAnother"
            role="status"
            class="mt-4 rounded-md bg-subtle px-3 py-2 text-sm text-text"
        >
            You already hold a ticket for this event. Claiming this one puts a
            second ticket in your name.
        </p>

        <p class="mt-4 text-sm text-text-muted">
            Claiming puts the ticket in your name: it appears under
            <strong>My tickets</strong>, and only you can download it or choose
            its seat.
        </p>

        <div class="mt-6">
            <Button type="button" :disabled="claiming" @click="claim">
                <TicketIcon class="size-4" />
                Claim this ticket
            </Button>
        </div>
    </div>
</template>
