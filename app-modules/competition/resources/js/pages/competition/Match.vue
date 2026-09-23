<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { page as competitionPage } from '@/routes/competition';
import { page as teamPage } from '@/routes/competition/team';
import { sectionLabel, sideName } from '../../components/bracket/bracketModel';
import { formatEventDateTime } from '../../components/public/eventTime';
import { matchStateLabel } from '../../components/public/labels';
import MatchHistory from '../../components/public/MatchHistory.vue';
import MatchResultPanel from '../../components/public/MatchResultPanel.vue';
import { usePollWhile } from '../../components/public/usePollWhile';

/**
 * One match (design §10): its sides, scores and time for everyone; for a
 * member of a side also the history (§8.6 shows it "to the sides and the
 * desk") and report / confirm / dispute. Polled while the competition runs,
 * so a side waiting on the other's report sees it arrive. The match time is
 * shown on the event's wall clock (ADR-0027, §10a "Time"), as the hub card
 * shows it.
 */
const props = defineProps<{
    page: Konventix.Competition.Data.MatchPageData;
}>();

usePollWhile(() => props.page.pollSeconds);

const onASide = computed(() => props.page.viewer.position !== null);

function teamHref(teamId: string): string {
    return teamPage({
        slug: props.page.event.slug,
        competition: props.page.competition.slug,
        team: teamId,
    }).url;
}
</script>

<template>
    <Head
        :title="`${props.page.match.sides.map((side) => sideName(side)).join(` ${$t('vs')} `)} – ${props.page.competition.name}`"
    />

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
                :title="
                    [
                        sectionLabel(props.page.match.bracket),
                        $t('Round :round', {
                            round: String(props.page.match.round),
                        }),
                        $t('Match :position', {
                            position: String(props.page.match.position),
                        }),
                    ]
                        .filter((part) => part !== null)
                        .join(' · ')
                "
                :description="
                    props.page.match.scheduledAt
                        ? formatEventDateTime(
                              props.page.match.scheduledAt,
                              props.page.event.timezone,
                          )
                        : $t('Not scheduled yet')
                "
            />
        </div>

        <section
            class="flex flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4"
            aria-labelledby="sides-heading"
        >
            <h2 id="sides-heading" class="sr-only">{{ $t('Pairing') }}</h2>
            <p class="text-sm text-text-muted">
                {{
                    props.page.match.disputed
                        ? $t('In dispute')
                        : matchStateLabel(props.page.match.state)
                }}
            </p>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="side in props.page.match.sides"
                    :key="side.position"
                    class="flex items-center justify-between gap-3 text-lg"
                    :class="
                        side.winner ? 'font-semibold text-success' : 'text-text'
                    "
                >
                    <Link
                        v-if="side.teamId"
                        :href="teamHref(side.teamId)"
                        class="underline-offset-4 hover:underline"
                        >{{ sideName(side) }}</Link
                    >
                    <span v-else class="text-text-muted">{{
                        sideName(side)
                    }}</span>
                    <span>
                        {{ side.score ?? '–' }}
                        <span v-if="side.winner" class="sr-only"
                            >({{ $t('Winner') }})</span
                        >
                    </span>
                </li>
            </ul>
        </section>

        <MatchResultPanel
            v-if="onASide"
            :competition="props.page.competition"
            :match="props.page.match"
            :viewer="props.page.viewer"
        />

        <MatchHistory v-if="onASide" :history="props.page.history" />
    </div>
</template>
