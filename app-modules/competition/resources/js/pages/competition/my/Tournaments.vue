<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';

/**
 * "Meine Turniere" (design §10): the teams this person is on, the entries
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
 * entry is "unvollständig" without saying that it still counts is how a team
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
 * Participant copy is German (ADR-0001). Names come from the server's
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
    isCaptain: boolean;
    inviteCode: string | null;
    members: Member[];
    entries: Entry[];
};

const props = defineProps<{ brandId: string; teams: Team[] }>();

const stateLabels: Record<string, string> = {
    incomplete: 'Noch nicht vollzählig',
    registered: 'Angemeldet',
    checked_in: 'Eingecheckt',
    withdrawn: 'Zurückgezogen',
    disqualified: 'Disqualifiziert',
};

function stateLabel(state: string): string {
    return stateLabels[state] ?? state;
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

function leave(team: Team): void {
    router.delete(`/my/tournaments/teams/${team.id}/members/me`, {
        preserveScroll: true,
    });
}

function regenerate(team: Team): void {
    router.post(
        `/my/tournaments/teams/${team.id}/invite-code`,
        {},
        { preserveScroll: true },
    );
}

function withdraw(entry: Entry): void {
    router.delete(`/my/tournaments/entries/${entry.id}`, {
        preserveScroll: true,
    });
}

/**
 * `canCheckIn` says the mode and the window allow it; it does **not** know
 * whether the whole roster has been through the event door, which only the
 * Action can answer. So the button is offered and the check-in can still
 * refuse — and the controller answers with a field error on `entrant`
 * naming who is still outside. Without this the captain clicks "Einchecken"
 * and nothing whatsoever happens, which reads as a broken page.
 *
 * Keyed by entry, so the message lands on the entry it belongs to. Same
 * shape as `Join.vue`'s refusal: the server's message, verbatim.
 */
const refusals = ref<Record<string, string | undefined>>({});

function checkIn(entry: Entry): void {
    refusals.value = { ...refusals.value, [entry.id]: undefined };

    router.post(
        `/my/tournaments/entries/${entry.id}/check-in`,
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
 * means there is nothing worth saying.
 */
function checkInHint(entry: Entry): string {
    if (entry.state === 'checked_in') {
        return 'Ihr seid eingecheckt.';
    }

    if (entry.checkInMode === 'desk') {
        return 'Die Orga checkt euch am Turnier-Desk ein.';
    }

    if (entry.checkInMode === 'self_service' && !entry.canCheckIn) {
        return entry.checkInOpensAt
            ? `Check-in ab ${formatDateTime(entry.checkInOpensAt)}.`
            : 'Der Check-in öffnet, sobald die Orga eine Startzeit gesetzt hat.';
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
    <Head title="Meine Turniere" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <Heading
            as="h1"
            title="Meine Turniere"
            description="Deine Teams, eure Anmeldungen und der Einladungscode zum Teilen."
        />

        <p class="text-sm text-text-muted">
            Du hast einen Code bekommen?
            <Link
                href="/my/tournaments/join"
                class="underline underline-offset-4"
                >Hier beitreten</Link
            >.
        </p>

        <p v-if="props.teams.length === 0" class="text-sm text-text-muted">
            Du bist noch in keinem Team. Melde dich auf der Turnierseite an, um
            eins zu gründen, oder tritt mit einem Einladungscode bei.
        </p>

        <section
            v-for="team in props.teams"
            :key="team.id"
            class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-medium text-text">{{ team.name }}</h2>
                <span v-if="team.isCaptain" class="text-xs text-text-muted"
                    >Du bist Captain</span
                >
            </div>

            <div
                v-if="team.inviteCode"
                class="flex flex-wrap items-center gap-3"
            >
                <span class="text-sm text-text-muted">Einladungscode</span>
                <code
                    class="rounded bg-surface px-2 py-1 font-mono tracking-[0.3em] text-text"
                >
                    {{ team.inviteCode }}
                </code>
                <Button variant="outline" size="sm" @click="regenerate(team)"
                    >Neuen Code erzeugen</Button
                >
            </div>

            <ul class="flex flex-col gap-1">
                <li
                    v-for="member in team.members"
                    :key="member.userId"
                    class="text-sm text-text"
                >
                    {{ member.displayName }}
                    <span v-if="member.isYou" class="text-text-muted"
                        >(du)</span
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
                            v-if="checkInHint(entry)"
                            class="text-xs text-text-muted"
                        >
                            {{ checkInHint(entry) }}
                        </span>

                        <Button
                            v-if="entry.canCheckIn"
                            size="sm"
                            @click="checkIn(entry)"
                            >Einchecken</Button
                        >

                        <Button
                            v-if="team.isCaptain && canWithdraw(entry)"
                            variant="outline"
                            size="sm"
                            @click="withdraw(entry)"
                        >
                            Anmeldung zurückziehen
                        </Button>
                    </div>

                    <InputError class="w-full" :message="refusals[entry.id]" />
                </li>
            </ul>

            <p v-else class="text-sm text-text-muted">
                Dieses Team ist noch für kein Turnier angemeldet.
            </p>

            <div>
                <Button variant="ghost" size="sm" @click="leave(team)"
                    >Team verlassen</Button
                >
            </div>
        </section>
    </div>
</template>
