<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { page as matchPage } from '@/routes/competition/match';
import { formatEventDateTime } from '../public/eventTime';
import { matchStateLabel } from '../public/labels';
import { bracketSections, sideName } from './bracketModel';

/**
 * The bracket as tables, round by round (design §10, ADR-0055): the
 * keyboard- and screen-reader-accessible form of the canvas, and the default
 * on a narrow screen. It reads the same `bracketSections()` of the same
 * `BracketData` the canvas lays out, so both show the same pairings
 * (`bracketModel.test.ts`).
 *
 * Every row links to its match, which is where the history and the result
 * forms are. Its time is on the event's wall clock (ADR-0027), as there.
 */
const props = defineProps<{
    bracket: Konventix.Competition.Data.BracketData;
    eventSlug: string;
    competitionSlug: string;
    eventTimezone: string | null;
}>();

const sections = computed(() => bracketSections(props.bracket));

function href(matchId: string): string {
    return matchPage({
        slug: props.eventSlug,
        competition: props.competitionSlug,
        match: matchId,
    }).url;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <section
            v-for="section in sections"
            :key="section.key"
            class="flex flex-col gap-4"
        >
            <h3 v-if="section.label" class="text-base font-medium text-text">
                {{ section.label }}
            </h3>

            <div
                v-for="round in section.rounds"
                :key="round.round"
                class="overflow-x-auto"
            >
                <table class="w-full text-left text-sm">
                    <caption
                        class="pb-2 text-left text-sm font-medium text-text"
                    >
                        {{
                            round.label
                        }}
                    </caption>
                    <thead class="text-text-muted">
                        <tr>
                            <th scope="col" class="py-1 pr-3">Spiel</th>
                            <th scope="col" class="py-1 pr-3">Paarung</th>
                            <th scope="col" class="py-1 pr-3">Zeit</th>
                            <th scope="col" class="py-1">Stand</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-text">
                        <tr v-for="match in round.matches" :key="match.id">
                            <td class="py-2 pr-3 align-top">
                                <Link
                                    :href="href(match.id)"
                                    class="underline underline-offset-4"
                                >
                                    Spiel {{ match.position }}
                                </Link>
                            </td>
                            <td class="py-2 pr-3 align-top">
                                <template
                                    v-for="(side, index) in match.sides"
                                    :key="side.position"
                                >
                                    <template v-if="index > 0">
                                        gegen
                                    </template>
                                    <span
                                        :class="
                                            side.winner
                                                ? 'font-semibold text-success'
                                                : side.teamName
                                                  ? ''
                                                  : 'text-text-muted'
                                        "
                                    >
                                        {{ sideName(side) }}
                                        <template v-if="side.score !== null">
                                            ({{ side.score }})
                                        </template>
                                        <span
                                            v-if="side.winner"
                                            class="sr-only"
                                        >
                                            (Sieger)
                                        </span>
                                    </span>
                                </template>
                            </td>
                            <td class="py-2 pr-3 align-top text-text-muted">
                                {{
                                    match.scheduledAt
                                        ? formatEventDateTime(
                                              match.scheduledAt,
                                              props.eventTimezone,
                                          )
                                        : '—'
                                }}
                            </td>
                            <td class="py-2 align-top">
                                <span v-if="match.disputed" class="text-danger"
                                    >Einspruch</span
                                >
                                <span v-else class="text-text-muted">{{
                                    matchStateLabel(match.state)
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
