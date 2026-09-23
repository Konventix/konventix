<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    assignee as assigneeRoute,
    knownIssue as knownIssueRoute,
    externalReference as externalReferenceRoute,
    state as stateRoute,
} from '@/routes/admin/support/requests';
import { supportStateLabel } from './supportConsole';

/**
 * Everything staff may do to a report (design §9.2), in one panel and four
 * separate writes — one route, one Action and one audit entry each.
 *
 * **The state buttons are exactly `allowedNext()`**, resolved on the server
 * from the transition table of §4.3. A button the Action would refuse is a
 * button nobody should be shown; the only way to reach the 422 is two
 * operators acting on the same report at once, which is honest.
 *
 * The assignee picker lists ULIDs, not names: the accounts live in Identity
 * and this module may not read them (ADR-0004), which is the same reason
 * Platform's own role editor prints them. "Assign to me" is what makes that
 * workable day to day.
 *
 * The known-issue toggle is the one control here that changes what an
 * unrelated visitor sees — the notice becomes the banner in everybody
 * else's report dialog — so it is spelled out rather than shortened to a
 * switch.
 */
const props = defineProps<{
    requestId: string;
    state: Konventix.Support.Enums.SupportRequestState;
    allowedNext: Konventix.Support.Enums.SupportRequestState[];
    assignedToUserId: string | null;
    assignees: string[];
    currentUserId: string | null;
    isKnownIssue: boolean;
    knownIssueNotice: string | null;
    externalReference: string | null;
}>();

const transition = useForm<{
    state: Konventix.Support.Enums.SupportRequestState | '';
}>({ state: '' });

const assignment = useForm<{ assignedToUserId: string | null }>({
    assignedToUserId: props.assignedToUserId,
});

const knownIssue = useForm({
    isKnownIssue: props.isKnownIssue,
    knownIssueNotice: props.knownIssueNotice ?? '',
});

const reference = useForm({
    externalReference: props.externalReference ?? '',
});

// ADR-0038: the two forms somebody types into. The state buttons and the
// assignee picker submit the moment they are used, so they are never dirty.
useUnsavedChanges(() => knownIssue.isDirty || reference.isDirty);

function moveTo(next: Konventix.Support.Enums.SupportRequestState): void {
    transition.state = next;
    transition.patch(stateRoute(props.requestId).url, { preserveScroll: true });
}

function assign(userId: string | null): void {
    assignment.assignedToUserId = userId;
    assignment.patch(assigneeRoute(props.requestId).url, {
        preserveScroll: true,
    });
}

function saveKnownIssue(): void {
    knownIssue.patch(knownIssueRoute(props.requestId).url, {
        preserveScroll: true,
    });
}

function saveReference(): void {
    reference.patch(externalReferenceRoute(props.requestId).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ $t('Triage') }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-6 text-sm">
            <div class="flex flex-col gap-2">
                <h3 class="font-medium">
                    {{
                        $t('State — currently :state', {
                            state: supportStateLabel(props.state),
                        })
                    }}
                </h3>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="next in props.allowedNext"
                        :key="next"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="transition.processing"
                        @click="moveTo(next)"
                    >
                        {{ supportStateLabel(next) }}
                    </Button>
                </div>
                <InputError :message="transition.errors.state" />
                <p
                    v-if="props.allowedNext.length === 0"
                    class="text-xs text-text-muted"
                >
                    {{ $t('Nowhere to go from here.') }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="support-assignee">{{ $t('Assignee') }}</Label>
                <select
                    id="support-assignee"
                    class="h-9 rounded-md border border-border bg-surface px-3 font-mono text-xs"
                    :value="props.assignedToUserId ?? ''"
                    :aria-invalid="
                        assignment.errors.assignedToUserId ? 'true' : undefined
                    "
                    aria-describedby="support-assignee-hint"
                    @change="
                        assign(
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">{{ $t('Unassigned') }}</option>
                    <option
                        v-for="candidate in props.assignees"
                        :key="candidate"
                        :value="candidate"
                    >
                        {{ candidate
                        }}{{
                            candidate === props.currentUserId
                                ? ` ${$t('(you)')}`
                                : ''
                        }}
                    </option>
                </select>
                <p id="support-assignee-hint" class="text-xs text-text-muted">
                    {{
                        $t(
                            'Everyone who may triage reports filed here. Accounts are named by their id: the display name belongs to Identity, which this module does not read.',
                        )
                    }}
                </p>
                <InputError :message="assignment.errors.assignedToUserId" />
                <Button
                    v-if="
                        props.currentUserId &&
                        props.assignedToUserId !== props.currentUserId &&
                        props.assignees.includes(props.currentUserId)
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    @click="assign(props.currentUserId)"
                >
                    {{ $t('Assign to me') }}
                </Button>
            </div>

            <form class="flex flex-col gap-2" @submit.prevent="saveKnownIssue">
                <h3 class="font-medium">{{ $t('Known issue') }}</h3>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="support-known-issue"
                        :model-value="knownIssue.isKnownIssue"
                        @update:model-value="
                            (value: boolean | 'indeterminate') =>
                                (knownIssue.isKnownIssue = value === true)
                        "
                    />
                    <Label for="support-known-issue" class="font-normal">
                        {{ $t('Everybody about to report this sees a banner') }}
                    </Label>
                </div>
                <Label for="support-known-issue-notice" class="text-xs">
                    {{ $t('What the banner says') }}
                </Label>
                <Textarea
                    id="support-known-issue-notice"
                    v-model="knownIssue.knownIssueNotice"
                    rows="3"
                    aria-describedby="support-known-issue-hint"
                    :aria-invalid="
                        knownIssue.errors.knownIssueNotice ? 'true' : undefined
                    "
                />
                <p
                    id="support-known-issue-hint"
                    class="text-xs text-text-muted"
                >
                    {{
                        $t(
                            "Strangers read this. Leave it empty and the banner names the category instead — never the reporter's own words.",
                        )
                    }}
                </p>
                <InputError :message="knownIssue.errors.knownIssueNotice" />
                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="knownIssue.processing"
                    >
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="knownIssue.isDirty"
                        role="status"
                        class="text-xs text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>

            <form class="flex flex-col gap-2" @submit.prevent="saveReference">
                <Label for="support-external-reference">
                    {{ $t('External reference') }}
                </Label>
                <Input
                    id="support-external-reference"
                    v-model="reference.externalReference"
                    :placeholder="$t('#306, ticket number, anything')"
                    aria-describedby="support-external-reference-hint"
                    :aria-invalid="
                        reference.errors.externalReference ? 'true' : undefined
                    "
                />
                <p
                    id="support-external-reference-hint"
                    class="text-xs text-text-muted"
                >
                    {{
                        $t(
                            'Free text by design: this module deep-links whatever tracker you really work in, it does not integrate with one.',
                        )
                    }}
                </p>
                <InputError :message="reference.errors.externalReference" />
                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="reference.processing"
                    >
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="reference.isDirty"
                        role="status"
                        class="text-xs text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
