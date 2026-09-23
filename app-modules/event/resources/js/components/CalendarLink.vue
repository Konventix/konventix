<script setup lang="ts">
import { CalendarPlus } from '@lucide/vue';
import { calendar } from '@/routes/event';

/**
 * Event's own contribution to `event.landing` (spec §7), registered by
 * `EventServiceProvider::registerSlots()`. Styling borrowed from the seat
 * picker's link in Resources (`SeatSummarySlot.vue`'s "Choose your seat"
 * button) — the landing page has no action button of its own to match.
 *
 * A plain anchor, not Inertia's `<Link>`: this points at a file download
 * (`Content-Disposition: attachment`), and an Inertia visit would try to
 * fetch it as a page instead of letting the browser save it — the same
 * reasoning `admin/orders/Show.vue` follows for its invoice PDF link.
 */
const props = defineProps<{ eventSlug: string }>();
</script>

<template>
    <a
        :href="calendar(props.eventSlug).url"
        class="inline-flex min-h-11 w-fit items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
    >
        <CalendarPlus class="size-4" />
        {{ $t('Add to calendar') }}
    </a>
</template>
