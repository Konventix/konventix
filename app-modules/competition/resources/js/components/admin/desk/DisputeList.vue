<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { matchLabel } from './matchLabel';

/**
 * The open disputes (design §8.4; §14 "the desk hears about a dispute from
 * its screen, not a message") — first on the page, because each one holds a
 * bracket up and §13's alert fires on one older than half an hour.
 *
 * Both claims side by side with the reason the dispute was raised; a proof a
 * side attached can be opened, none can be added here (§8.7, #322).
 * Resolving opens the result dialog, which demands the note both teams will
 * read.
 */
type Match = Konventix.Competition.Data.DeskMatchData;

const props = defineProps<{
    disputes: Konventix.Competition.Data.DeskDisputeData[];
    matches: Match[];
    powers: Konventix.Competition.Data.DeskPowersData;
}>();

const emit = defineEmits<{ resolve: [match: Match] }>();

const matchesById = computed(
    () => new Map(props.matches.map((match) => [match.id, match])),
);

function sideName(matchId: string, position: number): string {
    return (
        matchesById.value
            .get(matchId)
            ?.sides.find((side) => side.position === position)?.teamName ??
        trans('Side :position', { position: String(position) })
    );
}

function canResolve(
    dispute: Konventix.Competition.Data.DeskDisputeData,
): boolean {
    return dispute.recorded
        ? props.powers.correctResults
        : props.powers.recordResults;
}
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="desk-disputes">
        <h2 id="desk-disputes" class="text-lg font-medium text-text">
            {{ $t('Open disputes') }}
            <span class="text-text-muted">({{ props.disputes.length }})</span>
        </h2>

        <p v-if="props.disputes.length === 0" class="text-sm text-text-muted">
            {{ $t('No match is disputed.') }}
        </p>

        <article
            v-for="dispute in props.disputes"
            :key="dispute.matchId"
            class="flex flex-col gap-3 rounded-xl border border-danger bg-surface-raised p-4"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-medium text-text">
                    {{ matchLabel(dispute) }}
                </h3>
                <span class="text-sm text-text-muted">
                    {{
                        $t('Disputed :time', {
                            time: formatDateTime(dispute.disputedAt),
                        })
                    }}
                    <template v-if="dispute.recorded">
                        · {{ $t('a result stands; resolving corrects it') }}
                    </template>
                </span>
            </div>

            <p v-if="dispute.reason" class="text-sm text-text">
                “{{ dispute.reason }}”
            </p>

            <ul class="flex flex-col gap-1 text-sm">
                <li
                    v-for="report in dispute.reports"
                    :key="report.entrantId"
                    class="flex flex-wrap items-baseline gap-2"
                >
                    <span class="font-medium text-text">{{
                        $t(':team says', { team: report.teamName })
                    }}</span>
                    <span class="text-text">
                        {{
                            Object.entries(report.scores)
                                .map(
                                    ([position, score]) =>
                                        `${sideName(dispute.matchId, Number(position))} ${score ?? '–'}`,
                                )
                                .join(' : ')
                        }}
                    </span>
                    <a
                        v-if="report.proofUrl"
                        :href="report.proofUrl"
                        target="_blank"
                        rel="noopener"
                        class="underline underline-offset-4 hover:text-accent"
                        >{{ $t('View proof') }}</a
                    >
                </li>
                <li v-if="dispute.reports.length === 0" class="text-text-muted">
                    {{
                        $t(
                            'Raised against the recorded result; no claims filed.',
                        )
                    }}
                </li>
            </ul>

            <div v-if="canResolve(dispute) && matchesById.get(dispute.matchId)">
                <Button
                    size="sm"
                    @click="emit('resolve', matchesById.get(dispute.matchId)!)"
                >
                    {{ $t('Resolve') }}
                </Button>
            </div>
        </article>
    </section>
</template>
