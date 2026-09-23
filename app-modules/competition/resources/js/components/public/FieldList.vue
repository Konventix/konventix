<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { page as teamPage } from '@/routes/competition/team';

/**
 * Who is in, and who would like to be (design §5.3, §10): the entered teams
 * with their rosters, and beside them the public "interested to play" list,
 * so people looking for a team and teams looking for people read the same
 * screen. Names from `ParticipantProfile`.
 */
const props = defineProps<{
    eventSlug: string;
    competitionSlug: string;
    teams: Konventix.Competition.Data.EnteredTeamData[];
    interested: Konventix.Competition.Data.InterestedPersonData[];
}>();

function href(teamId: string): string {
    return teamPage({
        slug: props.eventSlug,
        competition: props.competitionSlug,
        team: teamId,
    }).url;
}
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2">
        <section class="flex flex-col gap-3" aria-labelledby="teams-heading">
            <h2 id="teams-heading" class="text-lg font-medium text-text">
                Teams ({{ props.teams.length }})
            </h2>

            <p v-if="props.teams.length === 0" class="text-sm text-text-muted">
                Noch kein Team angemeldet.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="team in props.teams"
                    :key="team.teamId"
                    class="rounded-lg border border-border px-3 py-2"
                >
                    <Link
                        :href="href(team.teamId)"
                        class="font-medium text-text underline-offset-4 hover:underline"
                        >{{ team.name }}</Link
                    >
                    <span
                        v-if="team.state === 'incomplete'"
                        class="ml-2 text-xs text-warning"
                        >noch nicht vollzählig</span
                    >
                    <span
                        v-else-if="team.state === 'checked_in'"
                        class="ml-2 text-xs text-success"
                        >eingecheckt</span
                    >
                    <p class="text-sm text-text-muted">
                        {{ team.members.join(', ') }}
                    </p>
                </li>
            </ul>
        </section>

        <section
            class="flex flex-col gap-3"
            aria-labelledby="interested-heading"
        >
            <h2 id="interested-heading" class="text-lg font-medium text-text">
                Suchen ein Team ({{ props.interested.length }})
            </h2>

            <p
                v-if="props.interested.length === 0"
                class="text-sm text-text-muted"
            >
                Niemand auf der Liste.
            </p>

            <ul v-else class="flex flex-col gap-1">
                <li
                    v-for="(person, index) in props.interested"
                    :key="index"
                    class="text-sm text-text"
                >
                    {{ person.displayName }}
                    <span v-if="person.note" class="text-text-muted">
                        – {{ person.note }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
