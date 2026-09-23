<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { page as competitionPage } from '@/routes/competition';
import { page as matchPage } from '@/routes/competition/match';
import { sideName } from '../../components/bracket/bracketModel';
import { formatEventDateTime } from '../../components/public/eventTime';
import { matchStateLabel } from '../../components/public/labels';
import { usePollWhile } from '../../components/public/usePollWhile';

/**
 * A team (design §10): its roster and its matches across the event — a team
 * is per event and may play several competitions (D-CMP-14). Names from
 * `ParticipantProfile`.
 */
const props = defineProps<{
    page: Konventix.Competition.Data.TeamPageData;
}>();

usePollWhile(() => props.page.pollSeconds);

function href(competitionSlug: string, matchId: string): string {
    return matchPage({
        slug: props.page.event.slug,
        competition: competitionSlug,
        match: matchId,
    }).url;
}
</script>

<template>
    <Head :title="`${props.page.teamName} – ${props.page.event.name}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <div class="flex flex-col gap-2">
            <Link
                :href="
                    competitionPage({
                        slug: props.page.event.slug,
                        competition: props.page.competition.slug,
                    }).url
                "
                class="text-sm text-text-muted underline-offset-4 hover:underline"
            >
                {{ props.page.competition.name }}
            </Link>
            <Heading
                as="h1"
                :title="props.page.teamName"
                :description="props.page.members.join(', ')"
            />
        </div>

        <section class="flex flex-col gap-3" aria-labelledby="matches-heading">
            <h2 id="matches-heading" class="text-lg font-medium text-text">
                {{ $t('Matches') }}
            </h2>

            <p
                v-if="props.page.matches.length === 0"
                class="text-sm text-text-muted"
            >
                {{ $t('No matches yet.') }}
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="entry in props.page.matches"
                    :key="entry.match.id"
                    class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2 text-sm"
                >
                    <span class="text-text-muted">
                        {{ entry.competitionName }} ·
                        {{
                            $t('Round :round', {
                                round: String(entry.match.round),
                            })
                        }}
                        ·
                        {{
                            entry.match.scheduledAt
                                ? formatEventDateTime(
                                      entry.match.scheduledAt,
                                      props.page.event.timezone,
                                  )
                                : $t('not scheduled yet')
                        }}
                        · {{ matchStateLabel(entry.match.state) }}
                    </span>
                    <Link
                        :href="href(entry.competitionSlug, entry.match.id)"
                        class="text-text underline underline-offset-4"
                    >
                        <template
                            v-for="(side, index) in entry.match.sides"
                            :key="side.position"
                        >
                            <template v-if="index > 0">
                                {{ $t('vs') }}
                            </template>
                            {{ sideName(side)
                            }}<template v-if="side.score !== null">
                                ({{ side.score }})</template
                            >
                        </template>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
