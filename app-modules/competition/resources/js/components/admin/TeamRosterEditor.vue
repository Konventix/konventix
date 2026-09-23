<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rename as renameTeam } from '@/routes/admin/competition-teams';
import {
    add as addMember,
    move as moveMember,
    remove as removeMember,
} from '@/routes/admin/competition-teams/members';

/**
 * The organizer's roster powers over one team (design §5.2, `ManageEntrants`):
 * rename it, add a player found by search, remove one, or move one to
 * another team of the same event.
 *
 * Every rule a self-service join obeys still applies on the server — the
 * §5.1 gate, the roster bound, the completeness recompute — so a refused
 * change comes back as an error on `entrant` and the page shows it; this
 * component offers, it does not decide.
 *
 * The search asks the page for its `people` prop by partial reload, and the
 * server lists only people with a confirmed registration for the event or
 * a ticket in their own name for it (§5.1 and its #330 addendum), which is
 * everyone `AddTeamMember` would accept.
 */
type Member = { userId: string; displayName: string };

const props = defineProps<{
    teamId: string;
    teamName: string;
    members: Member[];
    teams: { id: string; name: string }[];
    people: Konventix.Competition.Data.PersonOptionData[];
}>();

const name = ref(props.teamName);
const search = ref('');
const moveTo = ref<Record<string, string>>({});

watch(
    () => props.teamName,
    (value) => (name.value = value),
);

let pending: ReturnType<typeof setTimeout> | undefined;

watch(search, (term) => {
    clearTimeout(pending);
    pending = setTimeout(
        () =>
            router.reload({
                only: ['people'],
                data: { search: term },
            }),
        250,
    );
});

const options = { preserveScroll: true };

function rename(): void {
    router.put(renameTeam(props.teamId).url, { name: name.value }, options);
}

function add(userId: string): void {
    router.post(
        addMember(props.teamId).url,
        { userId },
        {
            ...options,
            onSuccess: () => (search.value = ''),
        },
    );
}

function remove(member: Member): void {
    router.delete(removeMember(props.teamId).url, {
        ...options,
        data: { userId: member.userId },
    });
}

function move(member: Member): void {
    router.post(
        moveMember(props.teamId).url,
        { userId: member.userId, toTeamId: moveTo.value[member.userId] },
        options,
    );
}
</script>

<template>
    <div class="flex flex-col gap-3 border-t border-border pt-3">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="rename">
            <div class="grid gap-1">
                <Label :for="`team-name-${props.teamId}`">{{
                    $t('Team name')
                }}</Label>
                <Input
                    :id="`team-name-${props.teamId}`"
                    v-model="name"
                    maxlength="80"
                    required
                />
            </div>
            <Button
                type="submit"
                size="sm"
                variant="outline"
                :disabled="name === props.teamName"
            >
                {{ $t('Rename') }}
            </Button>
        </form>

        <ul class="flex flex-col gap-2">
            <li
                v-for="member in props.members"
                :key="member.userId"
                class="flex flex-wrap items-center gap-2 text-sm"
            >
                <span class="min-w-32 text-text">{{ member.displayName }}</span>
                <Button size="sm" variant="ghost" @click="remove(member)">
                    {{ $t('Remove') }}
                    <span class="sr-only">{{ member.displayName }}</span>
                </Button>
                <label
                    :for="`move-${props.teamId}-${member.userId}`"
                    class="sr-only"
                    >{{
                        $t('Move :name to', { name: member.displayName })
                    }}</label
                >
                <select
                    :id="`move-${props.teamId}-${member.userId}`"
                    v-model="moveTo[member.userId]"
                    class="h-8 rounded-md border border-border bg-surface px-2 text-sm"
                >
                    <option :value="undefined" disabled>
                        {{ $t('Move to…') }}
                    </option>
                    <option
                        v-for="team in props.teams.filter(
                            (candidate) => candidate.id !== props.teamId,
                        )"
                        :key="team.id"
                        :value="team.id"
                    >
                        {{ team.name }}
                    </option>
                </select>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!moveTo[member.userId]"
                    @click="move(member)"
                >
                    {{ $t('Move') }}
                </Button>
            </li>
        </ul>

        <div class="grid gap-1">
            <Label :for="`add-${props.teamId}`">{{ $t('Add a player') }}</Label>
            <Input
                :id="`add-${props.teamId}`"
                v-model="search"
                type="search"
                :placeholder="$t('Name or username')"
                autocomplete="off"
            />
            <ul
                v-if="search.length >= 2"
                class="flex flex-col gap-1 text-sm"
                aria-live="polite"
            >
                <li
                    v-for="person in props.people"
                    :key="person.userId"
                    class="flex items-center gap-2"
                >
                    <span class="text-text">{{ person.displayName }}</span>
                    <span class="text-text-muted">@{{ person.username }}</span>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="add(person.userId)"
                    >
                        {{ $t('Add') }}
                        <span class="sr-only">{{ person.displayName }}</span>
                    </Button>
                </li>
                <li v-if="props.people.length === 0" class="text-text-muted">
                    {{
                        $t(
                            'Nobody with a confirmed registration or a ticket in their name matches.',
                        )
                    }}
                </li>
            </ul>
        </div>
    </div>
</template>
