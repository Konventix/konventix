<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

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
}>();

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
        `/entrants/${entrant.id}/fill`,
        { userIds: selection(entrant.id) },
        {
            preserveScroll: true,
            onSuccess: () =>
                (picked.value = { ...picked.value, [entrant.id]: [] }),
        },
    );
}

function decline(entrant: EntrantRow): void {
    router.post(
        `/entrants/${entrant.id}/decline`,
        {},
        { preserveScroll: true },
    );
}

function withdraw(entrant: EntrantRow): void {
    router.post(
        `/entrants/${entrant.id}/withdraw`,
        {},
        { preserveScroll: true },
    );
}

function disqualify(entrant: EntrantRow): void {
    router.post(
        `/entrants/${entrant.id}/disqualify`,
        {},
        { preserveScroll: true },
    );
}

function regenerate(entrant: EntrantRow): void {
    router.post(
        `/competition-teams/${entrant.teamId}/invite-code`,
        {},
        { preserveScroll: true },
    );
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

        <p class="text-sm">
            <Link
                :href="`/competitions/${props.competition.id}/check-in`"
                class="underline underline-offset-4 hover:text-accent"
            >
                Check-in desk
            </Link>
        </p>

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
                </div>
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
    </div>
</template>
