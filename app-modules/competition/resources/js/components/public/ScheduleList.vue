<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/datetime';
import { page as matchPage } from '@/routes/competition/match';
import { sideName } from '../bracket/bracketModel';

/**
 * The schedule (design §10): the matches with a time that are still to be
 * played, soonest first. It is the bracket's own `scheduledAt`s, not a
 * second list from the server.
 */
const props = defineProps<{
    eventSlug: string;
    competitionSlug: string;
    matches: Konventix.Competition.Data.BracketMatchData[];
}>();

const upcoming = computed(() =>
    props.matches
        .filter(
            (match) =>
                match.scheduledAt !== null &&
                (match.state === 'scheduled' ||
                    match.state === 'pending' ||
                    match.state === 'live'),
        )
        .sort((a, b) => (a.scheduledAt! < b.scheduledAt! ? -1 : 1)),
);

function href(matchId: string): string {
    return matchPage({
        slug: props.eventSlug,
        competition: props.competitionSlug,
        match: matchId,
    }).url;
}
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="schedule-heading">
        <h2 id="schedule-heading" class="text-lg font-medium text-text">
            Spielplan
        </h2>

        <p v-if="upcoming.length === 0" class="text-sm text-text-muted">
            Keine angesetzten Spiele.
        </p>

        <ul v-else class="flex flex-col gap-1">
            <li
                v-for="match in upcoming"
                :key="match.id"
                class="flex flex-wrap gap-x-3 text-sm"
            >
                <span class="text-text-muted">{{
                    formatDateTime(match.scheduledAt!)
                }}</span>
                <Link
                    :href="href(match.id)"
                    class="text-text underline underline-offset-4"
                >
                    {{
                        match.sides
                            .map((side) => sideName(side))
                            .join(' gegen ')
                    }}
                </Link>
            </li>
        </ul>
    </section>
</template>
