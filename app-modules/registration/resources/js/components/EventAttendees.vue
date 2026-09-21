<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { useId } from 'vue';

/**
 * Registration's second contribution to Event's `event.landing` slot
 * (ADR-0064): who is coming. `listing` is null when the event's switch is
 * `off`, and then nothing renders — the count is not computed either, see
 * `RegistrationServiceProvider::registerSlots()`.
 *
 * The count shows under the event's switch alone; names need the person's
 * own opt-in too, and for a `members` list a signed-in viewer
 * (`listing.namesVisible`). A name is the username and nothing else, and
 * links to the person's page only where Community is active — the server
 * decided both, this component only renders what it was handed.
 */
const props = defineProps<{
    listing: Konventix.Registration.Data.EventAttendeesData | null;
}>();

const headingId = useId();
</script>

<template>
    <section
        v-if="props.listing"
        class="flex flex-col gap-3"
        :aria-labelledby="headingId"
    >
        <h2 :id="headingId" class="text-lg font-medium">Who's coming</h2>

        <p class="text-text-muted" data-test="attendee-count">
            {{ props.listing.count }}
            {{ props.listing.count === 1 ? 'attendee' : 'attendees' }}
            so far.
        </p>

        <ul
            v-if="props.listing.namesVisible && props.listing.attendees.length"
            class="flex flex-wrap gap-2"
        >
            <li
                v-for="attendee in props.listing.attendees"
                :key="attendee.username"
            >
                <Link
                    v-if="attendee.profileUrl"
                    :href="attendee.profileUrl"
                    class="inline-block rounded-full border border-border bg-surface-raised px-3 py-1 text-sm text-text transition hover:border-accent"
                >
                    {{ attendee.username }}
                </Link>
                <span
                    v-else
                    class="inline-block rounded-full border border-border bg-surface-raised px-3 py-1 text-sm text-text"
                >
                    {{ attendee.username }}
                </span>
            </li>
        </ul>

        <p v-if="!props.listing.namesVisible" class="text-sm">
            <Link
                :href="props.listing.url"
                class="inline-flex items-center gap-1 text-accent underline-offset-4 hover:underline"
            >
                Sign in to see who is coming
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </p>
        <p
            v-else-if="
                props.listing.namedCount > props.listing.attendees.length
            "
            class="text-sm"
        >
            <Link
                :href="props.listing.url"
                class="inline-flex items-center gap-1 text-accent underline-offset-4 hover:underline"
            >
                See all {{ props.listing.namedCount }} names
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </p>
    </section>
</template>
