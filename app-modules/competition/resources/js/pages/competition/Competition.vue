<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { tournaments } from '@/routes/competition/event';
import BracketView from '../../components/bracket/BracketView.vue';
import EntryPanel from '../../components/public/EntryPanel.vue';
import { formatEventDateTime } from '../../components/public/eventTime';
import FieldList from '../../components/public/FieldList.vue';
import {
    formatLabel,
    statusLabel,
    teamSizeLabel,
} from '../../components/public/labels';
import ScheduleList from '../../components/public/ScheduleList.vue';
import ScoreEntryForm from '../../components/public/ScoreEntryForm.vue';
import Standings from '../../components/public/Standings.vue';
import { usePollWhile } from '../../components/public/usePollWhile';

/**
 * A competition's public page (design §10): description and rules, the
 * calls to action, the bracket — canvas and table from one `BracketData` —
 * or the leaderboard, the standings, the schedule, the teams with their
 * rosters and the interested list. Polled every 30 s while it runs.
 *
 * Description and rules are the organizer's markdown rendered on the server
 * with raw HTML stripped and unsafe link targets dropped
 * (`TournamentPages::markdown()`), which is what makes `v-html` safe here.
 */
const props = defineProps<{
    page: Konventix.Competition.Data.CompetitionPageData;
}>();

usePollWhile(() => props.page.pollSeconds);
</script>

<template>
    <Head
        :title="`${props.page.competition.name} – ${props.page.event.name}`"
    />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 py-6">
        <div class="flex flex-col gap-2">
            <Link
                :href="tournaments(props.page.event.slug).url"
                class="text-sm text-text-muted underline-offset-4 hover:underline"
            >
                Alle Turniere – {{ props.page.event.name }}
            </Link>
            <Heading
                as="h1"
                :title="props.page.competition.name"
                :description="`${props.page.competition.disciplineName} · ${teamSizeLabel(props.page.competition.teamSize)} · ${formatLabel(props.page.competition.format)}`"
            />
            <p class="text-sm text-text">
                <span
                    :class="
                        props.page.competition.status === 'cancelled'
                            ? 'text-danger'
                            : 'text-text'
                    "
                    >{{ statusLabel(props.page.competition.status) }}</span
                >
                ·
                {{ props.page.competition.entrants
                }}<template v-if="props.page.competition.maxEntrants !== null">
                    / {{ props.page.competition.maxEntrants }}</template
                >
                Teams
                <template v-if="props.page.competition.startsAt">
                    · Start
                    {{
                        formatEventDateTime(
                            props.page.competition.startsAt,
                            props.page.event.timezone,
                        )
                    }}
                </template>
            </p>
        </div>

        <!-- eslint-disable-next-line vue/no-v-html -->
        <div
            v-if="props.page.competition.descriptionHtml"
            class="flex flex-col gap-3 text-text [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
            v-html="props.page.competition.descriptionHtml"
        />

        <details
            v-if="props.page.competition.rulesHtml"
            class="rounded-xl border border-border p-4"
        >
            <summary class="cursor-pointer font-medium text-text">
                Regeln
            </summary>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div
                class="mt-3 flex flex-col gap-3 text-text [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
                v-html="props.page.competition.rulesHtml"
            />
        </details>

        <EntryPanel
            v-if="
                props.page.competition.status === 'published' ||
                props.page.viewer.enteredTeamName
            "
            :event="props.page.event"
            :competition="props.page.competition"
            :viewer="props.page.viewer"
        />

        <template v-if="props.page.competition.format === 'leaderboard'">
            <ScoreEntryForm
                v-if="props.page.viewer.canSubmitScore"
                :competition="props.page.competition"
            />
            <p
                v-else-if="
                    props.page.viewer.enteredTeamName &&
                    props.page.competition.status === 'running' &&
                    props.page.competition.proofRequired
                "
                class="text-sm text-text"
            >
                Für dieses Turnier ist zu jedem Ergebnis ein Nachweis Pflicht.
                Die Turnierleitung nimmt Ergebnis und Nachweis am Turnier-Desk
                entgegen.
            </p>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="board-heading"
            >
                <h2 id="board-heading" class="text-lg font-medium text-text">
                    Bestenliste
                </h2>
                <Standings
                    :standings="props.page.standings"
                    :unit="props.page.competition.unit"
                    :decimals="props.page.competition.decimals"
                />
            </section>
        </template>

        <template v-else>
            <section
                class="flex flex-col gap-3"
                aria-labelledby="bracket-heading"
            >
                <h2 id="bracket-heading" class="text-lg font-medium text-text">
                    Turnierbaum
                </h2>
                <BracketView
                    v-if="props.page.bracket"
                    :bracket="props.page.bracket"
                    :event-slug="props.page.event.slug"
                    :competition-slug="props.page.competition.slug"
                    :event-timezone="props.page.event.timezone"
                />
                <p v-else class="text-sm text-text-muted">
                    Der Turnierbaum wird nach dem Anmeldeschluss ausgelost.
                </p>
            </section>

            <ScheduleList
                v-if="props.page.bracket"
                :event-slug="props.page.event.slug"
                :competition-slug="props.page.competition.slug"
                :matches="props.page.bracket.matches"
                :event-timezone="props.page.event.timezone"
            />

            <section
                class="flex flex-col gap-3"
                aria-labelledby="standings-heading"
            >
                <h2
                    id="standings-heading"
                    class="text-lg font-medium text-text"
                >
                    Platzierungen
                </h2>
                <Standings :standings="props.page.standings" :unit="null" />
            </section>
        </template>

        <FieldList
            :event-slug="props.page.event.slug"
            :competition-slug="props.page.competition.slug"
            :teams="props.page.teams"
            :interested="props.page.interested"
        />
    </div>
</template>
