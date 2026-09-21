<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

/**
 * Every name the viewer may see for one event (ADR-0064 §7), paginated.
 * The server has already decided that the event is visible, that its
 * switch is on and that this viewer may see names — a guest on a
 * `members` list was sent to sign in before this page rendered — so the
 * page only lays out what it was handed.
 *
 * Names are usernames and nothing else, ordered by username; a name links
 * to the person's page only where Community is active, which is why the
 * link is a nullable prop and not a route helper here.
 */
type Attendee = Konventix.Registration.Data.AttendeeData;

const props = defineProps<{
    listing: Konventix.Registration.Data.EventAttendeesData;
    attendees: {
        data: Attendee[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
}>();
</script>

<template>
    <Head :title="`Attendees – ${props.listing.eventName}`" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <p class="text-sm">
            <Link
                :href="props.listing.eventUrl"
                class="inline-flex items-center gap-1 text-text-muted underline-offset-4 hover:underline"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                {{ props.listing.eventName }}
            </Link>
        </p>

        <header class="flex flex-col gap-1">
            <h1 class="text-3xl font-semibold">Who's coming</h1>
            <p class="text-text-muted" data-test="attendee-count">
                {{ props.listing.count }}
                {{ props.listing.count === 1 ? 'attendee' : 'attendees' }}
                so far<template v-if="props.attendees.total > 0"
                    >, {{ props.attendees.total }} listed by name</template
                >.
            </p>
        </header>

        <ul v-if="props.attendees.data.length > 0" class="flex flex-wrap gap-2">
            <li
                v-for="attendee in props.attendees.data"
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
        <p v-else class="text-text-muted">
            Nobody has chosen to be listed by name yet.
        </p>

        <nav
            v-if="props.attendees.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Link
                v-if="props.attendees.prev_page_url"
                :href="props.attendees.prev_page_url"
                class="text-accent underline-offset-4 hover:underline"
            >
                Previous
            </Link>
            <span v-else aria-hidden="true" />
            <span class="text-text-muted">
                Page {{ props.attendees.current_page }} of
                {{ props.attendees.last_page }}
            </span>
            <Link
                v-if="props.attendees.next_page_url"
                :href="props.attendees.next_page_url"
                class="text-accent underline-offset-4 hover:underline"
            >
                Next
            </Link>
            <span v-else aria-hidden="true" />
        </nav>
    </div>
</template>
