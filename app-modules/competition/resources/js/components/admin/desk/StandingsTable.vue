<script setup lang="ts">
/**
 * The stage's table (design §9 "the bracket or table"): placements as
 * `CalculateStandings` last wrote them. A leaderboard ranks on its aggregate
 * score, which travels as `tiebreaker` (§7.2); every other format on its
 * record. Grouped stages show their group.
 */
const props = defineProps<{
    standings: Konventix.Competition.Data.DeskStandingData[];
    /** The leaderboard's unit, or null for a format that plays matches. */
    unit: string | null;
}>();
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="desk-standings">
        <h2 id="desk-standings" class="text-lg font-medium text-text">
            Standings
        </h2>

        <p v-if="props.standings.length === 0" class="text-sm text-text-muted">
            No standings yet.
        </p>

        <table v-else class="w-full text-left text-sm">
            <thead class="text-text-muted">
                <tr>
                    <th scope="col" class="py-1 pr-3">#</th>
                    <th scope="col" class="py-1 pr-3">Team</th>
                    <template v-if="props.unit !== null">
                        <th scope="col" class="py-1">Score</th>
                    </template>
                    <template v-else>
                        <th scope="col" class="py-1 pr-3">Group</th>
                        <th scope="col" class="py-1 pr-3">W</th>
                        <th scope="col" class="py-1 pr-3">L</th>
                        <th scope="col" class="py-1 pr-3">D</th>
                        <th scope="col" class="py-1">Points</th>
                    </template>
                </tr>
            </thead>
            <tbody class="divide-y divide-border text-text">
                <tr v-for="row in props.standings" :key="row.entrantId">
                    <td class="py-1 pr-3">{{ row.placement ?? '—' }}</td>
                    <td class="py-1 pr-3">{{ row.teamName }}</td>
                    <template v-if="props.unit !== null">
                        <td class="py-1">
                            {{
                                row.tiebreaker === null
                                    ? '—'
                                    : `${row.tiebreaker} ${props.unit}`
                            }}
                        </td>
                    </template>
                    <template v-else>
                        <td class="py-1 pr-3">{{ row.group ?? '' }}</td>
                        <td class="py-1 pr-3">{{ row.wins }}</td>
                        <td class="py-1 pr-3">{{ row.losses }}</td>
                        <td class="py-1 pr-3">{{ row.draws }}</td>
                        <td class="py-1">{{ row.points }}</td>
                    </template>
                </tr>
            </tbody>
        </table>
    </section>
</template>
