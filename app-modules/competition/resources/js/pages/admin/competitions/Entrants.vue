<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { inviteCode } from '@/routes/admin/competition-teams';
import {
    checkIn as competitionCheckIn,
    desk as competitionDesk,
} from '@/routes/admin/competitions';
import {
    decline as declineEntrant,
    disqualify as disqualifyEntrant,
    fill as fillEntrant,
    withdraw as withdrawEntrant,
} from '@/routes/admin/entrants';
import TeamRosterEditor from '../../../components/admin/TeamRosterEditor.vue';
import ConfirmDialog from '../../../components/confirm/ConfirmDialog.vue';
import { useConfirmation } from '../../../components/confirm/useConfirmation';

/**
 * The field of one competition (design §5.2, §5.4, §5.5).
 *
 * The screen exists for **§5.4's prompt**: once signup is closed, every
 * incomplete roster is listed with the two choices the design names — fill it
 * up from the interested list, or decline it and free the slot. `start`
 * refuses while any incomplete entrant is left, so this is not a screen the
 * organizer can click past; it is the one that unblocks the tournament.
 *
 * Filling is a multi-select over the interested list rather than a search
 * box, because that list is short, ordered by who spoke up first, and carries
 * the note each person wrote about what they can play.
 *
 * With `ManageEntrants` every roster opens into the organizer's powers of
 * §5.2 — rename, add a player found by search, remove, move
 * (`TeamRosterEditor`); a refusal comes back as an error on `entrant`.
 *
 * Names come from `ParticipantProfile` on the server. Console copy is
 * English.
 *
 * a11y: each checkbox has a real `<label>`, and the two decisions are
 * `<button>`s with text, not icons — `.ai/rules/js.md`.
 */
type Member = { userId: string; displayName: string };

type EntrantRow = {
    id: string;
    state: string;
    withdrawalReason: string | null;
    teamId: string;
    teamName: string;
    inviteCode: string | null;
    missing: number;
    members: Member[];
};

type Interested = { userId: string; displayName: string; note: string | null };

const props = defineProps<{
    competition: {
        id: string;
        name: string;
        status: string;
        teamSize: number;
        maxEntrants: number | null;
    };
    entrants: EntrantRow[];
    interested: Interested[];
    teams: { id: string; name: string }[];
    canManage: boolean;
    people?: Konventix.Competition.Data.PersonOptionData[];
}>();

const page = usePage();

const refusal = computed(
    () => (page.props.errors as Record<string, string | undefined>).entrant,
);

const editing = ref<string | null>(null);

function toggleEditing(teamId: string): void {
    editing.value = editing.value === teamId ? null : teamId;
}

const picked = ref<Record<string, string[]>>({});

const incomplete = computed(() =>
    props.entrants.filter((entrant) => entrant.state === 'incomplete'),
);

const active = computed(() =>
    props.entrants.filter(
        (entrant) =>
            entrant.state === 'registered' || entrant.state === 'checked_in',
    ),
);

const gone = computed(() =>
    props.entrants.filter(
        (entrant) =>
            entrant.state === 'withdrawn' || entrant.state === 'disqualified',
    ),
);

function selection(entrantId: string): string[] {
    return picked.value[entrantId] ?? [];
}

function toggle(entrantId: string, userId: string, on: boolean): void {
    const current = new Set(selection(entrantId));

    if (on) {
        current.add(userId);
    } else {
        current.delete(userId);
    }

    picked.value = { ...picked.value, [entrantId]: [...current] };
}

function fill(entrant: EntrantRow): void {
    router.post(
        fillEntrant(entrant.id).url,
        { userIds: selection(entrant.id) },
        {
            preserveScroll: true,
            onSuccess: () =>
                (picked.value = { ...picked.value, [entrant.id]: [] }),
        },
    );
}

/**
 * Decline, withdraw and disqualify are terminal entrant states, and a
 * disqualification while the competition runs forfeits the team's bracket
 * position, so each asks first (final review, part 3, I2).
 */
const {
    pending: pendingQuestion,
    open: asking,
    ask,
    confirm,
} = useConfirmation();

function decline(entrant: EntrantRow): void {
    ask({
        title: `Decline ${entrant.teamName}?`,
        description:
            'The entry is withdrawn as an incomplete roster, its members are notified, and the slot is freed. It cannot be undone.',
        confirmLabel: 'Decline entry',
        cancelLabel: 'Keep it',
        run: () =>
            router.post(
                declineEntrant(entrant.id).url,
                {},
                { preserveScroll: true },
            ),
    });
}

function withdraw(entrant: EntrantRow): void {
    ask({
        title: `Withdraw ${entrant.teamName}?`,
        description:
            'The team leaves this competition and its slot is freed. It cannot be undone.',
        confirmLabel: 'Withdraw entry',
        cancelLabel: 'Keep it',
        run: () =>
            router.post(
                withdrawEntrant(entrant.id).url,
                {},
                { preserveScroll: true },
            ),
    });
}

function disqualify(entrant: EntrantRow): void {
    ask({
        title: `Disqualify ${entrant.teamName}?`,
        description:
            props.competition.status === 'running'
                ? 'The team is taken out of every match it has still to play, and the bracket goes on without it. It cannot be undone.'
                : 'The team is out of this competition for good. It cannot be undone.',
        confirmLabel: 'Disqualify',
        cancelLabel: 'Keep it',
        run: () =>
            router.post(
                disqualifyEntrant(entrant.id).url,
                {},
                { preserveScroll: true },
            ),
    });
}

function regenerate(entrant: EntrantRow): void {
    router.post(inviteCode(entrant.teamId).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Entrants — ${props.competition.name}`" />

    <div class="flex w-full flex-col gap-6 p-4">
        <Heading
            as="h1"
            :title="`Entrants — ${props.competition.name}`"
            :description="`Team size ${props.competition.teamSize}. ${active.length} full roster(s), ${incomplete.length} incomplete.`"
        />

        <p class="flex flex-wrap gap-4 text-sm">
            <Link
                :href="competitionDesk(props.competition.id).url"
                class="underline underline-offset-4 hover:text-accent"
            >
                Tournament desk
            </Link>
            <Link
                :href="competitionCheckIn(props.competition.id).url"
                class="underline underline-offset-4 hover:text-accent"
            >
                Check-in desk
            </Link>
        </p>

        <InputError :message="refusal" role="alert" />

        <section v-if="incomplete.length > 0" class="flex flex-col gap-4">
            <h2 class="text-lg font-medium text-text">Incomplete rosters</h2>
            <p class="text-sm text-text-muted">
                Each of these holds a slot. Fill it from the interested list, or
                decline it and free the slot. The competition cannot start while
                any of them is left.
            </p>

            <article
                v-for="entrant in incomplete"
                :key="entrant.id"
                class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h3 class="font-medium text-text">
                        {{ entrant.teamName }}
                    </h3>
                    <span class="text-sm text-warning"
                        >{{ entrant.missing }} player(s) short</span
                    >
                </div>

                <ul class="flex flex-wrap gap-2 text-sm text-text-muted">
                    <li v-for="member in entrant.members" :key="member.userId">
                        {{ member.displayName }}
                    </li>
                </ul>

                <fieldset
                    v-if="props.interested.length > 0"
                    class="flex flex-col gap-2"
                >
                    <legend class="text-sm text-text-muted">Interested</legend>

                    <label
                        v-for="person in props.interested"
                        :key="person.userId"
                        class="flex items-start gap-2 text-sm text-text"
                    >
                        <Checkbox
                            :model-value="
                                selection(entrant.id).includes(person.userId)
                            "
                            @update:model-value="
                                (value: boolean | 'indeterminate') =>
                                    toggle(
                                        entrant.id,
                                        person.userId,
                                        value === true,
                                    )
                            "
                        />
                        <span>
                            {{ person.displayName }}
                            <span v-if="person.note" class="text-text-muted"
                                >— {{ person.note }}</span
                            >
                        </span>
                    </label>
                </fieldset>

                <p v-else class="text-sm text-text-muted">
                    Nobody registered interest in this competition.
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        :disabled="selection(entrant.id).length === 0"
                        @click="fill(entrant)"
                    >
                        Fill up
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="decline(entrant)"
                        >Decline</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="regenerate(entrant)"
                        >New invite code</Button
                    >
                    <Button
                        v-if="props.canManage"
                        variant="ghost"
                        size="sm"
                        :aria-expanded="editing === entrant.teamId"
                        @click="toggleEditing(entrant.teamId)"
                        >Edit roster</Button
                    >
                </div>

                <TeamRosterEditor
                    v-if="props.canManage && editing === entrant.teamId"
                    :team-id="entrant.teamId"
                    :team-name="entrant.teamName"
                    :members="entrant.members"
                    :teams="props.teams"
                    :people="props.people ?? []"
                />
            </article>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-medium text-text">Entered</h2>

            <p v-if="active.length === 0" class="text-sm text-text-muted">
                No full roster has entered yet.
            </p>

            <article
                v-for="entrant in active"
                :key="entrant.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface-raised p-4"
            >
                <div class="flex min-w-0 flex-col">
                    <span class="font-medium text-text">{{
                        entrant.teamName
                    }}</span>
                    <span class="text-sm text-text-muted">
                        {{
                            entrant.members
                                .map((member) => member.displayName)
                                .join(', ')
                        }}
                    </span>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="props.canManage"
                        variant="ghost"
                        size="sm"
                        :aria-expanded="editing === entrant.teamId"
                        @click="toggleEditing(entrant.teamId)"
                        >Edit roster</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        @click="withdraw(entrant)"
                        >Withdraw</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        @click="disqualify(entrant)"
                        >Disqualify</Button
                    >
                </div>

                <TeamRosterEditor
                    v-if="props.canManage && editing === entrant.teamId"
                    class="w-full"
                    :team-id="entrant.teamId"
                    :team-name="entrant.teamName"
                    :members="entrant.members"
                    :teams="props.teams"
                    :people="props.people ?? []"
                />
            </article>
        </section>

        <section v-if="gone.length > 0" class="flex flex-col gap-2">
            <h2 class="text-lg font-medium text-text">
                No longer in the field
            </h2>

            <ul class="flex flex-col gap-1 text-sm text-text-muted">
                <li v-for="entrant in gone" :key="entrant.id">
                    {{ entrant.teamName }} — {{ entrant.state }}
                    <template v-if="entrant.withdrawalReason">
                        ({{ entrant.withdrawalReason }})</template
                    >
                </li>
            </ul>
        </section>

        <ConfirmDialog
            v-model:open="asking"
            :confirmation="pendingQuestion"
            @confirm="confirm"
        />
    </div>
</template>
