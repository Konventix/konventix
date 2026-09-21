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
 * the markup is `EventOffers` itself — and since #234 that includes the
 * end price: `PublicOffers::forEvent()` computes it behind Platform's
 * `Pricing\EndPriceCalculator`, so a block on a static page prints the same
 * amount as the landing page and `/shop`. The block pipeline needed no
 * post-processing hook for it, which is why the contract lives in Platform
 * rather than in `ShopController`.
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
