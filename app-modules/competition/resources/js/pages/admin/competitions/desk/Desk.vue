<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { formatDateTime } from '@/lib/datetime';
import {
    checkIn as competitionCheckIn,
    edit as editCompetition,
    entrants as competitionEntrants,
} from '@/routes/admin/competitions';
import DisputeList from '../../../../components/admin/desk/DisputeList.vue';
import MatchBoard from '../../../../components/admin/desk/MatchBoard.vue';
import ResultDialog from '../../../../components/admin/desk/ResultDialog.vue';
import type { ResultMode } from '../../../../components/admin/desk/ResultDialog.vue';
import ScheduleMatchDialog from '../../../../components/admin/desk/ScheduleMatchDialog.vue';
import SeedingPanel from '../../../../components/admin/desk/SeedingPanel.vue';
import StandingsTable from '../../../../components/admin/desk/StandingsTable.vue';
import VerificationQueue from '../../../../components/admin/desk/VerificationQueue.vue';

/**
 * The tournament desk (design §9, D-CMP-20, D-CMP-21): one competition as
 * the people running it see it on the day.
 *
 * Top to bottom in the order a desk needs it: the open disputes first,
 * because each one holds the bracket up; then the seeding and the draw; the
 * matches round by round, with the per-round "schedule all at" and the
 * record, correct and resolve dialogs; the standings; and for a leaderboard
 * the verification queue instead of a bracket.
 *
 * Check-in keeps its own screen (§14: "§9's slice may fold the panel in or
 * link to it") because it is worked at a table by the door; the desk links to
 * it and shows how far it has got. The rosters — rename a team, add, remove
 * or move a player — live on the entrants screen, which is `ManageEntrants`.
 *
 * A refused action (a draw after a result, a record on a disputed match)
 * comes back as an error on `desk` and is shown above everything else.
 * Console copy is English.
 */
type Match = Konventix.Competition.Data.DeskMatchData;

const props = defineProps<{
    desk: Konventix.Competition.Data.TournamentDeskData;
}>();

const page = usePage();

const deskError = computed(
    () => (page.props.errors as Record<string, string | undefined>).desk,
);

const resultOpen = ref(false);
const resultMode = ref<ResultMode>('record');
const scheduleOpen = ref(false);
const selected = ref<Match | null>(null);

function openResult(match: Match, mode: ResultMode): void {
    selected.value = match;
    resultMode.value = mode;
    resultOpen.value = true;
}

function openSchedule(match: Match): void {
    selected.value = match;
    scheduleOpen.value = true;
}

const stage = computed(() => props.desk.stage);
</script>

<template>
    <Head :title="`Desk — ${props.desk.name}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <Heading
            as="h1"
            :title="`Desk — ${props.desk.name}`"
            :description="`Status: ${props.desk.status.replace('_', ' ')}. ${props.desk.field.length} in the field, ${props.desk.checkedIn} checked in.`"
        />

        <nav class="flex flex-wrap gap-4 text-sm" aria-label="Competition">
            <Link
                :href="editCompetition(props.desk.competitionId).url"
                class="underline underline-offset-4 hover:text-accent"
                >Settings</Link
            >
            <Link
                :href="competitionEntrants(props.desk.competitionId).url"
                class="underline underline-offset-4 hover:text-accent"
                >Entrants and rosters</Link
            >
            <Link
                v-if="props.desk.powers.checkInEntrants"
                :href="competitionCheckIn(props.desk.competitionId).url"
                class="underline underline-offset-4 hover:text-accent"
                >Check-in desk</Link
            >
        </nav>

        <p class="text-sm text-text-muted">
            <template v-if="props.desk.startsAt">
                Starts {{ formatDateTime(props.desk.startsAt) }}.
            </template>
            <template v-else>No start time set.</template>
            Results:
            {{
                props.desk.resultSubmissionMode === 'desk_only'
                    ? 'recorded by the desk only'
                    : 'reported by the teams'
            }}.
        </p>

        <InputError :message="deskError" role="alert" />

        <p v-if="stage === null" class="text-sm text-text-muted">
            This competition has no stage to run.
        </p>

        <template v-else>
            <DisputeList
                v-if="!stage.isLeaderboard"
                :disputes="props.desk.disputes"
                :matches="props.desk.matches"
                :powers="props.desk.powers"
                @resolve="(match) => openResult(match, 'resolve')"
            />

            <SeedingPanel
                v-if="!stage.isLeaderboard"
                :stage="stage"
                :field="props.desk.field"
                :can-manage="props.desk.powers.manageSchedule"
            />

            <MatchBoard
                v-if="stage.generated && props.desk.matches.length > 0"
                :stage-id="stage.id"
                :matches="props.desk.matches"
                :starts-at="props.desk.startsAt"
                :powers="props.desk.powers"
                @result="openResult"
                @schedule="openSchedule"
            />

            <VerificationQueue
                v-if="stage.isLeaderboard && props.desk.powers.recordResults"
                :stage="stage"
                :queue="props.desk.queue"
                :field="props.desk.field"
            />

            <StandingsTable
                :standings="props.desk.standings"
                :unit="stage.isLeaderboard ? (stage.unit ?? '') : null"
            />
        </template>

        <ResultDialog
            v-model:open="resultOpen"
            :match="selected"
            :mode="resultMode"
        />
        <ScheduleMatchDialog
            v-model:open="scheduleOpen"
            :match="selected"
            :starts-at="props.desk.startsAt"
        />
    </div>
</template>
