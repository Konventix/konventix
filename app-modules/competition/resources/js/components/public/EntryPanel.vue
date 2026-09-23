<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { store as enterStore } from '@/routes/competition/entries';
import {
    destroy as interestDestroy,
    store as interestStore,
} from '@/routes/competition/interest';
import { join } from '@/routes/competition/my';
import { store as teamStore } from '@/routes/competition/my/teams';
import { edit as editLinkedAccounts } from '@/routes/linked-accounts';

/**
 * The competition page's calls to action (design §10): "the entry /
 * interest / join-by-code call-to-actions for a logged-in eligible person".
 *
 * Which of them shows is the server's reading (`CompetitionViewerData`);
 * every one of them is decided again by its Action, and a refusal comes back
 * as the form error beside the button that caused it.
 *
 * A team is started here, where the event is known — "My tournaments" has no
 * such form on purpose — and then entered with the select beside it: a team
 * the viewer captains shows up there as soon as it exists.
 *
 * A discipline may require a linked account of everyone who plays it
 * (design §5.6). The panel says so to every reader, and a signed-in viewer
 * who has not linked it gets the link to their account settings, the same
 * place the refusal sends them.
 */
const props = defineProps<{
    event: Konventix.Competition.Data.EventRefData;
    competition: Konventix.Competition.Data.CompetitionDetailData;
    viewer: Konventix.Competition.Data.CompetitionViewerData;
}>();

const entry = useForm({ teamId: props.viewer.enterableTeams[0]?.id ?? '' });
const team = useForm({ eventId: props.event.id, name: '' });
const interest = useForm({ note: '' });

function enter(): void {
    entry.post(enterStore(props.competition.id).url, { preserveScroll: true });
}

function startTeam(): void {
    team.post(teamStore().url, {
        preserveScroll: true,
        onSuccess: () => {
            team.reset('name');
            entry.teamId = props.viewer.enterableTeams[0]?.id ?? '';
        },
    });
}

function registerInterest(): void {
    interest.post(interestStore(props.competition.id).url, {
        preserveScroll: true,
        onSuccess: () => interest.reset(),
    });
}

function withdrawInterest(): void {
    router.delete(interestDestroy(props.competition.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-4"
        aria-labelledby="entry-heading"
    >
        <h2 id="entry-heading" class="text-lg font-medium text-text">
            {{ $t('Play') }}
        </h2>

        <p
            v-if="props.competition.requiredLinkedAccount"
            class="text-sm text-text-muted"
        >
            <template v-if="props.competition.teamSize === 1">
                {{
                    $t(
                        'This tournament needs you to have a linked :provider account.',
                        { provider: props.competition.requiredLinkedAccount },
                    )
                }}
            </template>
            <template v-else>
                {{
                    $t(
                        'This tournament needs a linked :provider account for everyone on the team.',
                        { provider: props.competition.requiredLinkedAccount },
                    )
                }}
            </template>
            <template
                v-if="
                    props.viewer.signedIn && props.viewer.missingLinkedAccount
                "
            >
                {{ $t('Yours is still missing:') }}
                <Link
                    :href="editLinkedAccounts().url"
                    class="underline underline-offset-4"
                    >{{
                        $t('Link your :provider account', {
                            provider: props.competition.requiredLinkedAccount,
                        })
                    }}</Link
                >.
            </template>
        </p>

        <p v-if="!props.viewer.signedIn" class="text-sm text-text-muted">
            {{ $t('To enter a team or put yourself on the interest list:') }}
            <Link :href="login().url" class="underline underline-offset-4">{{
                $t('Sign in')
            }}</Link
            >.
        </p>

        <p v-else-if="!props.viewer.eligible" class="text-sm text-text-muted">
            {{
                $t(
                    'Anyone with a confirmed registration for the event or a ticket in their own name can play.',
                )
            }}
        </p>

        <template v-else>
            <p v-if="props.viewer.enteredTeamName" class="text-sm text-text">
                {{ $t('You are playing for') }}
                <strong>{{ props.viewer.enteredTeamName }}</strong
                >.
            </p>

            <template v-else-if="props.competition.signupOpen">
                <form
                    v-if="props.viewer.enterableTeams.length > 0"
                    class="flex flex-col gap-2"
                    @submit.prevent="enter"
                >
                    <Label for="entry-team">{{
                        $t('Sign up your team')
                    }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <select
                            id="entry-team"
                            v-model="entry.teamId"
                            class="rounded-md border border-input bg-surface px-3 py-2 text-sm text-text"
                            :aria-invalid="
                                entry.errors.teamId ? 'true' : undefined
                            "
                            :aria-describedby="
                                entry.errors.teamId ? 'entry-error' : undefined
                            "
                        >
                            <option
                                v-for="option in props.viewer.enterableTeams"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }} ({{ option.members }}/{{
                                    props.competition.teamSize
                                }})
                            </option>
                        </select>
                        <Button type="submit" :disabled="entry.processing">
                            {{ $t('Sign up for this tournament') }}
                        </Button>
                    </div>
                    <p class="text-sm text-text-muted">
                        {{
                            $t(
                                'A team of fewer than :size people keeps its slot; the organizers fill it up when signup closes or talk to you.',
                                { size: String(props.competition.teamSize) },
                            )
                        }}
                    </p>
                    <InputError
                        id="entry-error"
                        :message="entry.errors.teamId"
                    />
                </form>

                <form class="flex flex-col gap-2" @submit.prevent="startTeam">
                    <Label for="team-name">{{ $t('Start a new team') }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <Input
                            id="team-name"
                            v-model="team.name"
                            maxlength="80"
                            class="max-w-xs"
                            :aria-invalid="
                                team.errors.name ? 'true' : undefined
                            "
                            :aria-describedby="
                                team.errors.name ? 'team-name-error' : undefined
                            "
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="team.processing || team.name === ''"
                        >
                            {{ $t('Start team') }}
                        </Button>
                    </div>
                    <InputError
                        id="team-name-error"
                        :message="team.errors.name"
                    />
                </form>

                <p class="text-sm text-text-muted">
                    {{ $t('Got an invite code?') }}
                    <Link
                        :href="join().url"
                        class="underline underline-offset-4"
                        >{{ $t('Join here') }}</Link
                    >.
                </p>
            </template>

            <p v-else class="text-sm text-text-muted">
                {{ $t('Signup is not open right now.') }}
            </p>

            <template
                v-if="
                    !props.viewer.enteredTeamName &&
                    props.competition.status === 'published'
                "
            >
                <div
                    v-if="props.viewer.interested"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="text-sm text-text">{{
                        $t('You are on the interest list.')
                    }}</span>
                    <Button variant="ghost" size="sm" @click="withdrawInterest">
                        {{ $t('Take me off the list') }}
                    </Button>
                </div>

                <form
                    v-else
                    class="flex flex-col gap-2"
                    @submit.prevent="registerInterest"
                >
                    <Label for="interest-note">{{
                        $t('No team yet? Join the interest list')
                    }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <Input
                            id="interest-note"
                            v-model="interest.note"
                            maxlength="140"
                            :placeholder="
                                $t('Optional: what you play, when you can')
                            "
                            class="max-w-md"
                            :aria-invalid="
                                interest.errors.note ? 'true' : undefined
                            "
                            :aria-describedby="
                                interest.errors.note
                                    ? 'interest-error'
                                    : undefined
                            "
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="interest.processing"
                        >
                            {{ $t('I would play') }}
                        </Button>
                    </div>
                    <InputError
                        id="interest-error"
                        :message="interest.errors.note"
                    />
                </form>
            </template>
        </template>
    </section>
</template>
