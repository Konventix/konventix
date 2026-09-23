<script setup lang="ts">
import { formatDateTime } from '@/lib/datetime';
import { historyLabel } from './labels';

/**
 * A match's history as its sides read it (design §8.6): what happened, when,
 * for which team, the score line and the note — a dispute's reason, the
 * desk's resolution, a correction's explanation. Oldest first.
 */
const props = defineProps<{
    history: Konventix.Competition.Data.MatchHistoryRowData[];
}>();

function scoreLine(scores: Record<number, number | null> | null): string {
    if (scores === null) {
        return '';
    }

    return Object.values(scores)
        .map((score) => (score === null ? '–' : String(score)))
        .join(' : ');
}
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="history-heading">
        <h2 id="history-heading" class="text-lg font-medium text-text">
            Verlauf
        </h2>

        <p v-if="props.history.length === 0" class="text-sm text-text-muted">
            Noch nichts passiert.
        </p>

        <ol v-else class="flex flex-col gap-2">
            <li
                v-for="(row, index) in props.history"
                :key="index"
                class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2 text-sm"
            >
                <span class="text-text">
                    {{ historyLabel(row.kind) }}
                    <template v-if="row.teamName">
                        · {{ row.teamName }}</template
                    >
                    <template v-if="row.scores">
                        · {{ scoreLine(row.scores) }}</template
                    >
                </span>
                <span v-if="row.at" class="text-xs text-text-muted">{{
                    formatDateTime(row.at)
                }}</span>
                <span v-if="row.note" class="text-text-muted">{{
                    row.note
                }}</span>
            </li>
        </ol>
    </section>
</template>
