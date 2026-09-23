<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { page as matchPage } from '@/routes/competition/match';
import { join } from '@/routes/competition/my';
import {
    checkIn as checkInEntry,
    withdraw as withdrawEntry,
} from '@/routes/competition/my/entries';
import { inviteCode, leave as leaveTeam } from '@/routes/competition/my/teams';
import ConfirmDialog from '../../../components/confirm/ConfirmDialog.vue';
import { useConfirmation } from '../../../components/confirm/useConfirmation';
import { formatEventDateTime } from '../../../components/public/eventTime';
import { usePollWhile } from '../../../components/public/usePollWhile';

/**
 * "My tournaments" (design §10): the teams this person is on, the entries
 * those teams hold, and the four things a participant may do — start a team,
 * share its invite code, leave, and (as captain) withdraw an entry.
 *
 * The invite code is rendered **only for the captain**, because the server
 * only sends it to the captain (§5.2). The template never has the others'
 * codes to leak.
 *
 * `incomplete` is shown as a state to be proud of rather than an error: a
 * roster below the team size still holds its slot, and the organizer will
 * either fill it from the interested list or ask (§5.4). Telling people their
 * entry is "incomplete" without saying that it still counts is how a team
 * signs up twice.
 *
 * **Check-in (§6) appears here only when the captain may actually do it.**
 * The mode decides: `self_service` gives the captain a button inside the
 * window of `checkInOpensMinutesBefore` minutes before the start, `desk`
 * says in words that the orga does it, and `none` says nothing at all
 * because there is nothing to do. The server decides `canCheckIn` and the
 * Action refuses anything this page gets wrong — the button is a
 * convenience, never the rule. When it refuses, the reason is rendered on
 * the entry: `canCheckIn` cannot know whether the whole roster has been
 * through the event door, so a click that is allowed here can still be
 * turned down there, and a button that silently does nothing is the one
 * outcome a participant cannot act on.
 *
 * There is deliberately **no "create a team" form here**. A team is started
 * from the competition you want to enter (§10's competition page), where the
 * event is already known — a form on this page could only ask the person to
 * type an event ULID, which is not a thing anybody has. The endpoint the
 * competition page posts to is `POST /my/tournaments/teams`.
 *
 * Above the teams, the two things that wait on the person (design §10):
 * results the other side reported that their side has not answered — linked
 * to the match page, where confirm, counter-report and dispute live — and
 * their leaderboard scores with the desk's verdict. Polled every 30 s while
 * one of their competitions runs.
 *
 * Copy comes from the catalog (ADR-0068). Names come from the server's
 * `ParticipantProfile` read model, never from Identity.
 */
type Member = { userId: string; displayName: string; isYou: boolean };

type Entry = {
    id: string;
    state: string;
    withdrawalReason: string | null;
    withdrawalLabel: string | null;
    competitionName: string;
    competitionStatus: string | null;
    checkInMode: string | null;
    checkedInAt: string | null;
    checkInOpensAt: string | null;
    canCheckIn: boolean;
};

type Team = {
    id: string;
    name: string;
    eventId: string;
    eventTimezone: string | null;
    isCaptain: boolean;
    inviteCode: string | null;
    members: Member[];
    entries: Entry[];
};

const props = defineProps<{
    brandId: string;
    teams: Team[];
    pendingConfirmations: Konventix.Competition.Data.PendingConfirmationData[];
    scoreEntries: Konventix.Competition.Data.MyScoreEntryData[];
    pollSeconds: number | null;
}>();

usePollWhile(() => props.pollSeconds);

function scoreStateLabel(state: string): string {
    const labels: Record<string, string> = {
        pending: trans('Waiting for the organizers'),
        verified: trans('Confirmed'),
        rejected: trans('Rejected'),
    };

    return labels[state] ?? state;
}

function confirmationHref(
    item: Konventix.Competition.Data.PendingConfirmationData,
): string | null {
    return item.eventSlug === null
        ? null
        : matchPage({
              slug: item.eventSlug,
              competition: item.competitionSlug,
              match: item.matchId,
          }).url;
}

function claim(scores: Record<number, number | null>): string {
    return Object.values(scores)
        .map((score) => (score === null ? '–' : String(score)))
        .join(' : ');
}

function stateLabel(state: string): string {
    const labels: Record<string, string> = {
        incomplete: trans('Not complete yet'),
        registered: trans('Signed up'),
        checked_in: trans('Checked in'),
        withdrawn: trans('Withdrawn'),
        disqualified: trans('Disqualified'),
    };

    return labels[state] ?? state;
}

function stateClass(state: string): string {
    if (state === 'registered' || state === 'checked_in') {
        return 'text-success';
    }

    if (state === 'incomplete') {
        return 'text-warning';
    }

    return 'text-text-muted';
}

/**
 * `canCheckIn` says the mode and the window allow it; it does **not** know
 * whether the whole roster has been through the event door, which only the
 * Action can answer. So the button is offered and the check-in can still
 * refuse — and the controller answers with a field error on `entrant`
 * naming who is still outside. Without this the captain clicks "Check in"
 * and nothing whatsoever happens, which reads as a broken page.
 *
 * Keyed by entry, so the message lands on the entry it belongs to. Same
 * shape as `Join.vue`'s refusal: the server's message, verbatim. A refused
 * withdrawal lands here too.
 */
const refusals = ref<Record<string, string | undefined>>({});

/**
 * Leaving and withdrawing ask first (final review, part 3, I2): back on the
 * team needs the captain to share the code again, and a withdrawn slot may
 * be gone for good once the field fills or signup closes.
 */
const {
    pending: pendingQuestion,
    open: asking,
    ask,
    confirm,
} = useConfirmation();

function leave(team: Team): void {
    ask({
        title: trans('Leave :team?', { team: team.name }),
        description: team.isCaptain
            ? trans(
                  'This also hands over the captain role. You can only get back on the team with the invite code from the new captain.',
              )
            : trans(
                  'You can only get back on the team with the invite code from the captain.',
              ),
        confirmLabel: trans('Leave team'),
        cancelLabel: trans('Cancel'),
        run: () =>
            router.delete(leaveTeam(team.id).url, {
                preserveScroll: true,
            }),
    });
}

function regenerate(team: Team): void {
    router.post(inviteCode(team.id).url, {}, { preserveScroll: true });
}

function withdraw(entry: Entry): void {
    ask({
        title: trans('Withdraw from :competition?', {
            competition: entry.competitionName,
        }),
        description: trans(
            'Your slot becomes free. If the field is full or signup has closed by then, you cannot get back in.',
        ),
        confirmLabel: trans('Withdraw entry'),
        cancelLabel: trans('Cancel'),
        run: () => {
            refusals.value = { ...refusals.value, [entry.id]: undefined };

            router.delete(withdrawEntry(entry.id).url, {
                preserveScroll: true,
                // The controller answers a refused withdrawal on `entrant`
                // (the competition started meanwhile); it lands on the
                // entry like a refused check-in (final review, part 3, I3).
                onError: (errors) => {
                    refusals.value = {
                        ...refusals.value,
                        [entry.id]: errors.entrant,
                    };
                },
            });
        },
    });
}

function checkIn(entry: Entry): void {
    refusals.value = { ...refusals.value, [entry.id]: undefined };

    router.post(
        checkInEntry(entry.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                refusals.value = {
                    ...refusals.value,
                    [entry.id]: errors.entrant,
                };
            },
        },
    );
}

/**
 * What to say about check-in when there is no button: the window has not
 * opened yet, the desk does it, or the team is already in. An empty string
 * means there is nothing worth saying. The opening time is on the event's
 * wall clock (ADR-0027), which is why the team, whose event it is, comes too.
 */
function checkInHint(entry: Entry, team: Team): string {
    if (entry.state === 'checked_in') {
        return trans('Your team is checked in.');
    }

    if (entry.checkInMode === 'desk') {
        return trans('The organizers check you in at the tournament desk.');
    }

    if (entry.checkInMode === 'self_service' && !entry.canCheckIn) {
        return entry.checkInOpensAt
            ? trans('Check-in from :time.', {
                  time: formatEventDateTime(
                      entry.checkInOpensAt,
                      team.eventTimezone,
                  ),
              })
            : trans(
                  'Check-in opens once the organizers have set a start time.',
              );
    }

    return '';
}

function canWithdraw(entry: Entry): boolean {
    return (
        (entry.state === 'incomplete' ||
            entry.state === 'registered' ||
            entry.state === 'checked_in') &&
        entry.competitionStatus !== 'running' &&
        entry.competitionStatus !== 'finished' &&
        entry.competitionStatus !== 'cancelled'
    );
}
</script>

<template>
    <Head :title="$t('My tournaments')" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <Heading
            as="h1"
            :title="$t('My tournaments')"
            :description="
                $t('Your teams, their entries and the invite code to share.')
            "
        />

        <p class="text-sm text-text-muted">
            {{ $t('Got a code?') }}
            <Link :href="join().url" class="underline underline-offset-4">{{
                $t('Join here')
            }}</Link
            >.
        </p>

        <section
            v-if="props.pendingConfirmations.length > 0"
            class="flex flex-col gap-2 rounded-xl border border-warning p-4"
            aria-labelledby="pending-heading"
        >
            <h2 id="pending-heading" class="text-lg font-medium text-text">
                {{ $t('Results to confirm') }}
            </h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="item in props.pendingConfirmations"
                    :key="item.matchId"
                    class="text-sm text-text"
                >
                    {{
                        $t(':competition: :opponent reports :score.', {
                            competition: item.competitionName,
                            opponent: item.opponentName,
                            score: claim(item.scores),
                        })
                    }}
                    <Link
                        v-if="confirmationHref(item)"
                        :href="confirmationHref(item)!"
                        class="underline underline-offset-4"
                        >{{ $t('Confirm or dispute') }}</Link
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="props.scoreEntries.length > 0"
            class="flex flex-col gap-2"
            aria-labelledby="scores-heading"
        >
            <h2 id="scores-heading" class="text-lg font-medium text-text">
                {{ $t('Submitted results') }}
            </h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="(entry, index) in props.scoreEntries"
                    :key="index"
                    class="text-sm text-text"
                >
                    {{ entry.competitionName }} · {{ entry.teamName }}
                    <template v-if="entry.heat !== null">
                        ·
                        {{
                            $t('Heat :heat', { heat: String(entry.heat) })
                        }}</template
                    >
                    · {{ entry.value }} ·
                    <span
                        :class="
                            entry.state === 'verified'
                                ? 'text-success'
                                : entry.state === 'rejected'
                                  ? 'text-danger'
                                  : 'text-text-muted'
                        "
                        >{{ scoreStateLabel(entry.state) }}</span
                    >
                    <template v-if="entry.rejectionNote">
                        – {{ entry.rejectionNote }}</template
                    >
                </li>
            </ul>
        </section>

        <p v-if="props.teams.length === 0" class="text-sm text-text-muted">
            {{
                $t(
                    'You are not on a team yet. Sign up on the tournament page to start one, or join with an invite code.',
                )
            }}
        </p>

        <section
            v-for="team in props.teams"
            :key="team.id"
            class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-medium text-text">{{ team.name }}</h2>
                <span v-if="team.isCaptain" class="text-xs text-text-muted">{{
                    $t('You are captain')
                }}</span>
            </div>

            <div
                v-if="team.inviteCode"
                class="flex flex-wrap items-center gap-3"
            >
                <span class="text-sm text-text-muted">{{
                    $t('Invite code')
                }}</span>
                <code
                    class="rounded bg-surface px-2 py-1 font-mono tracking-[0.3em] text-text"
                >
                    {{ team.inviteCode }}
                </code>
                <Button variant="outline" size="sm" @click="regenerate(team)">{{
                    $t('Generate a new code')
                }}</Button>
            </div>

            <ul class="flex flex-col gap-1">
                <li
                    v-for="member in team.members"
                    :key="member.userId"
                    class="text-sm text-text"
                >
                    {{ member.displayName }}
                    <span v-if="member.isYou" class="text-text-muted"
                        >({{ $t('you') }})</span
                    >
                </li>
            </ul>

            <ul v-if="team.entries.length > 0" class="flex flex-col gap-2">
                <li
                    v-for="entry in team.entries"
                    :key="entry.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
                >
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate text-sm text-text">{{
                            entry.competitionName
                        }}</span>
                        <span class="text-xs" :class="stateClass(entry.state)">
                            {{ stateLabel(entry.state) }}
                            <template v-if="entry.withdrawalLabel">
                                — {{ entry.withdrawalLabel }}</template
                            >
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            v-if="checkInHint(entry, team)"
                            class="text-xs text-text-muted"
                        >
                            {{ checkInHint(entry, team) }}
                        </span>

                        <Button
                            v-if="entry.canCheckIn"
                            size="sm"
                            @click="checkIn(entry)"
                            >{{ $t('Check in') }}</Button
                        >

                        <Button
                            v-if="team.isCaptain && canWithdraw(entry)"
                            variant="outline"
                            size="sm"
                            @click="withdraw(entry)"
                        >
                            {{ $t('Withdraw entry') }}
                        </Button>
                    </div>

                    <InputError
                        class="w-full"
                        role="alert"
                        :message="refusals[entry.id]"
                    />
                </li>
            </ul>

            <p v-else class="text-sm text-text-muted">
                {{ $t('This team is not signed up for any tournament yet.') }}
            </p>

            <div>
                <Button variant="ghost" size="sm" @click="leave(team)">{{
                    $t('Leave team')
                }}</Button>
            </div>
        </section>

        <ConfirmDialog
            v-model:open="asking"
            :confirmation="pendingQuestion"
            @confirm="confirm"
        />
    </div>
</template>
