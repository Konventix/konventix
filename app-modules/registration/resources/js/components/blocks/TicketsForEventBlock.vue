<script setup lang="ts">
import EventOffers from '../EventOffers.vue';

/**
 * The `tickets-for-event` static-page block: one event's public offers on a
 * composed page, under the heading the editor chose (the server falls back
 * to the event's name).
 *
 * Props are resolved server-side by
 * `Konventix\Registration\Blocks\TicketsForEventBlock::resolve()` and are
 * the very list the event landing page's `event.landing` slot renders, so
 * the markup is `EventOffers` itself — net amount and "excl. VAT" exactly as
 * the landing page prints them. A Commerce-computed gross amount (what
 * `/shop` shows) is not available in the block pipeline; see the module's
 * TODO.md against #234.
 *
 * `found: false` — the event is gone, another brand's, or not listable —
 * renders nothing rather than failing the page; so does an event with no
 * offers, because `EventOffers` hides itself when the list is empty.
 *
 * The props are `Konventix.Registration.Data.TicketsForEventResolvedData`,
 * spelled out because the SFC compiler cannot expand a global namespace
 * type inside `defineProps<>` (same as `EventHeroBlock.vue`).
 */
const props = defineProps<{
    found: boolean;
    heading: string | null;
    eventName: string | null;
    offers: Konventix.Registration.Data.PublicOfferData[];
}>();
</script>

<template>
    <EventOffers
        v-if="props.found"
        :offers="props.offers"
        :heading="props.heading ?? props.eventName ?? 'Register'"
    />
</template>
