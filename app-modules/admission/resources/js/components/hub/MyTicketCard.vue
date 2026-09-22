<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * The live hub's ticket card (D-HUB-05, design §5.2): the code the door
 * scans, without leaving the page the participant is already on.
 *
 * The props are `MyTicketCardData`'s fields, spread onto this component by
 * the hub shell (`v-bind="card.payload"`) — the shell never looks inside a
 * payload, which is what lets Platform render a card it has never heard of
 * (ADR-0003), so the generated interface is the whole contract.
 *
 * The scan code is rendered the way the ticket page renders it
 * (`TicketHolderBand`): server-rendered SVG markup in an opaque,
 * fixed-size box so it never scales below scannable, and `role="img"` with
 * a name, because the SVG itself says nothing a screen reader can use.
 *
 * Imports nothing of another module's: the URL is in the payload, so this
 * card needs no route helper and no foreign type.
 */
// Spelled out rather than `Konventix.Admission.Data.MyTicketCardData`: a
// bare namespaced type fails the SFC compiler's macro type resolver (see
// `tickets/Show.vue`). Mirrors `MyTicketCardData` field for field —
// including the ones the card does not print, so the shell's
// `v-bind="card.payload"` has nothing left to fall through onto the root
// element as an attribute.
type Props = {
    admissionId: string;
    eventName: string | null;
    role: string;
    qrSvg: string;
    ticketUrl: string;
    reentryHint: string;
};

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-6">
        <div
            role="img"
            aria-label="Scan-Code für den Einlass"
            class="size-40 shrink-0 rounded-lg bg-surface p-2 [&>svg]:size-full"
            v-html="props.qrSvg"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <p v-if="props.eventName" class="font-medium text-text">
                {{ props.eventName }}
            </p>
            <p class="text-sm text-text-muted capitalize">{{ props.role }}</p>
            <p class="text-sm text-text-muted">{{ props.reentryHint }}</p>

            <Link
                :href="props.ticketUrl"
                class="mt-1 self-start text-sm text-accent underline-offset-4 hover:underline"
            >
                Ticket öffnen
            </Link>
        </div>
    </div>
</template>
