<script setup lang="ts">
import { computed } from 'vue';
import { formatScore } from './scoreValue';

/**
 * The stage's table on the public page (design §10): placements as
 * `CalculateStandings` last wrote them. A leaderboard ranks on its aggregate
 * score, which travels as `tiebreaker` (§7.2); every other format on its
 * record. Grouped stages show their group.
 */
const props = defineProps<{
    standings: Konventix.Competition.Data.DeskStandingData[];
    /** The leaderboard's unit, or null for a format that plays matches. */
    unit: string | null;
    /** The leaderboard's decimal places (§7.2). */
    decimals?: number | null;
}>();

const grouped = computed(() =>
    props.standings.some((row) => row.group !== null),
);
</script>

<template>
    <p v-if="props.standings.length === 0" class="text-sm text-text-muted">
        Noch keine Platzierungen.
    </p>

    <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-text-muted">
                <tr>
                    <th scope="col" class="py-1 pr-3">Platz</th>
                    <th scope="col" class="py-1 pr-3">Team</th>
                    <th v-if="props.unit !== null" scope="col" class="py-1">
                        Wertung
                    </th>
                    <template v-else>
                        <th v-if="grouped" scope="col" class="py-1 pr-3">
                            Gruppe
                        </th>
                        <th scope="col" class="py-1 pr-3">
                            <abbr title="Siege">S</abbr>
                        </th>
                        <th scope="col" class="py-1 pr-3">
                            <abbr title="Niederlagen">N</abbr>
                        </th>
                        <th scope="col" class="py-1 pr-3">
                            <abbr title="Unentschieden">U</abbr>
                        </th>
                        <th scope="col" class="py-1">Punkte</th>
                    </template>
                </tr>
            </thead>
            <tbody class="divide-y divide-border text-text">
                <tr v-for="row in props.standings" :key="row.entrantId">
                    <td class="py-1 pr-3">{{ row.placement ?? '—' }}</td>
                    <td class="py-1 pr-3">{{ row.teamName }}</td>
                    <td v-if="props.unit !== null" class="py-1">
                        {{
                            formatScore(
                                row.tiebreaker,
                                props.unit,
                                props.decimals ?? null,
                            )
                        }}
                    </td>
                    <template v-else>
                        <td v-if="grouped" class="py-1 pr-3">
                            {{
                                row.group?.replace(/^group-/, '').toUpperCase()
                            }}
                        </td>
                        <td class="py-1 pr-3">{{ row.wins }}</td>
                        <td class="py-1 pr-3">{{ row.losses }}</td>
                        <td class="py-1 pr-3">{{ row.draws }}</td>
                        <td class="py-1">{{ row.points }}</td>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>
