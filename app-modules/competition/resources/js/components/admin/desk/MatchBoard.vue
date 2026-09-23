<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    toInstant,
    toLocalDateTimeInput,
} from '@/lib/datetime';
import { scheduleRound } from '@/routes/admin/competition-stages';
import { matchStateLabel } from '../../public/labels';
import { roundLabel } from './matchLabel';
import type { ResultMode } from './ResultDialog.vue';

/**
 * The drawn bracket, round by round (design §9, D-CMP-21).
 *
 * Each round carries its own "schedule all at": a LAN plays in waves, so one
 * instant for the whole round is the common case and the per-match time is
 * the exception. The first round starts at the competition's `starts_at`
 * unless the desk says otherwise; later rounds start empty, because only the
 * desk knows how long the one before will run (the estimator is #117).
 *
 * A match offers exactly the dialogs the viewer may use on it in its state:
 * record while it is open and undisputed, correct once it is finished,
 * resolve while it is disputed — with `CorrectResults` when a result already
 * stands (§8.4). The server checks all of it again.
 */
type Match = Konventix.Competition.Data.DeskMatchData;

const props = defineProps<{
    stageId: string;
    matches: Match[];
    startsAt: string | null;
    powers: Konventix.Competition.Data.DeskPowersData;
}>();

const emit = defineEmits<{
    result: [match: Match, mode: ResultMode];
    schedule: [match: Match];
}>();

type Round = {
    key: string;
    bracket: string | null;
    round: number;
    matches: Match[];
};

const rounds = computed((): Round[] => {
    const byKey = new Map<string, Round>();

    for (const match of props.matches) {
        const key = `${match.bracket ?? ''}|${match.round}`;

        if (!byKey.has(key)) {
            byKey.set(key, {
                key,
                bracket: match.bracket,
                round: match.round,
                matches: [],
            });
        }

        byKey.get(key)!.matches.push(match);
    }

    return [...byKey.values()];
});

const roundTimes = ref<Record<string, string>>({});

function roundTime(round: Round, index: number): string {
    return (
        roundTimes.value[round.key] ??
        (index === 0 ? toLocalDateTimeInput(props.startsAt) : '')
    );
}

function scheduleAll(round: Round, index: number): void {
    router.post(
        scheduleRound(props.stageId).url,
        {
            bracket: round.bracket,
            round: round.round,
            scheduledAt: toInstant(roundTime(round, index)),
        },
        { preserveScroll: true },
    );
}

/**
 * Recordable once no side is still to come. A slot nothing will ever fill is
 * a bye, and the match is settled by giving the lone side the win — the same
 * rule the server enforces (`guardEverySideHasArrived`).
 */
function playable(match: Match): boolean {
    return (
        match.sides.some((side) => side.entrantId !== null) &&
        !match.sides.some((side) => side.awaiting) &&
        (match.state === 'pending' ||
            match.state === 'scheduled' ||
            match.state === 'live')
    );
}

function canResolve(match: Match): boolean {
    return match.state === 'finished'
        ? props.powers.correctResults
        : props.powers.recordResults;
}
</script>

<template>
    <section class="flex flex-col gap-6" aria-labelledby="desk-bracket">
        <h2 id="desk-bracket" class="text-lg font-medium text-text">
            {{ $t('Matches') }}
        </h2>

        <div
            v-for="(round, index) in rounds"
            :key="round.key"
            class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h3 class="font-medium text-text">
                    {{ roundLabel(round.bracket, round.round) }}
                </h3>

                <form
                    v-if="props.powers.manageSchedule"
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="scheduleAll(round, index)"
                >
                    <DateTimeField
                        :id="`desk-round-${round.key}`"
                        :model-value="roundTime(round, index)"
                        :label="$t('Schedule all at')"
                        @update:model-value="
                            (value: string) => (roundTimes[round.key] = value)
                        "
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        :disabled="roundTime(round, index) === ''"
                    >
                        {{ $t('Schedule round') }}
                    </Button>
                </form>
            </div>

            <ul class="flex flex-col divide-y divide-border">
                <li
                    v-for="match in round.matches"
                    :key="match.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-2"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="text-sm text-text-muted">
                            {{
                                $t('Match :position', {
                                    position: String(match.position),
                                })
                            }}
                            <template v-if="match.scheduledAt">
                                · {{ formatDateTime(match.scheduledAt) }}
                            </template>
                        </span>
                        <span class="text-sm text-text">
                            <template
                                v-for="(side, sideIndex) in match.sides"
                                :key="side.position"
                            >
                                <template v-if="sideIndex > 0">
                                    {{ $t('vs') }}
                                </template>
                                <span
                                    :class="side.winner ? 'font-semibold' : ''"
                                >
                                    {{
                                        side.teamName ??
                                        (side.awaiting ? $t('TBD') : $t('Bye'))
                                    }}
                                    <template v-if="side.score !== null">
                                        ({{ side.score }})
                                    </template>
                                </span>
                            </template>
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="match.disputed" variant="destructive">
                            {{ $t('Disputed') }}
                        </Badge>
                        <Badge v-else variant="outline">{{
                            matchStateLabel(match.state)
                        }}</Badge>

                        <Button
                            v-if="
                                props.powers.manageSchedule &&
                                (match.state === 'pending' ||
                                    match.state === 'scheduled')
                            "
                            size="sm"
                            variant="ghost"
                            @click="emit('schedule', match)"
                        >
                            {{ $t('Set time') }}
                        </Button>
                        <Button
                            v-if="match.disputed && canResolve(match)"
                            size="sm"
                            @click="emit('result', match, 'resolve')"
                        >
                            {{ $t('Resolve') }}
                        </Button>
                        <Button
                            v-else-if="
                                props.powers.recordResults && playable(match)
                            "
                            size="sm"
                            @click="emit('result', match, 'record')"
                        >
                            {{ $t('Record') }}
                        </Button>
                        <Button
                            v-else-if="
                                props.powers.correctResults &&
                                match.state === 'finished'
                            "
                            size="sm"
                            variant="outline"
                            @click="emit('result', match, 'correct')"
                        >
                            {{ $t('Correct') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
