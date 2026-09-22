<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ModerationController from '@/actions/Konventix/Community/Http/Controllers/ModerationController';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime } from '@/lib/datetime';
import { show as showEvent } from '@/routes/admin/events';

/**
 * One event's shoutbox desk (design §8): the open report queue, the people
 * currently muted, the lock switch and the latest lines. Console copy, so
 * English (ADR-0001); what participants read — the mute reason — is typed
 * here but shown to them in the room.
 *
 * Gated on `ModerateCommunity` alone (D-COM-04): whoever works this desk
 * needs no admission to the event.
 *
 * The three verbs of D-COM-08: remove a line (reason required, kept for the
 * desk), mute a person for a time with a reason they are shown, lock the
 * wall. A removal and a lock ask for their reason in a prompt, as the
 * programme editor's cancel does; the mute is a form, because its reason is
 * a sentence somebody will read and its duration is a choice.
 */
const props = defineProps<{
    desk: Konventix.Community.Data.ShoutboxModerationData;
}>();

const page = usePage();
const errors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);

const room = computed(() => props.desk.room);
const eventId = computed(() => props.desk.eventId);

/** Whom the mute form is about; null while it is closed. */
const muteTarget = ref<{ userId: string; name: string } | null>(null);

const muteForm = useForm({
    reason: '',
    minutes: 10 as number | null,
});

const durations: Array<{ label: string; minutes: number | null }> = [
    { label: '5 minutes', minutes: 5 },
    { label: '10 minutes', minutes: 10 },
    { label: '30 minutes', minutes: 30 },
    { label: '1 hour', minutes: 60 },
    { label: 'Rest of the event', minutes: null },
];

useUnsavedChanges(() => muteForm.isDirty);

function openMute(userId: string, name: string | null): void {
    muteForm.reset();
    muteForm.clearErrors();
    muteTarget.value = { userId, name: name ?? 'Unknown participant' };
}

function closeMute(): void {
    muteTarget.value = null;
    muteForm.reset();
    muteForm.clearErrors();
}

function submitMute(): void {
    const target = muteTarget.value;

    if (target === null) {
        return;
    }

    muteForm.post(
        ModerationController.mute([eventId.value, target.userId]).url,
        { preserveScroll: true, onSuccess: closeMute },
    );
}

function unmute(userId: string): void {
    router.delete(ModerationController.unmute([eventId.value, userId]).url, {
        preserveScroll: true,
    });
}

/** The tombstone keeps this reason for the desk; the room never sees it. */
function removeLine(messageId: string): void {
    const reason = window.prompt(
        'Why is this line removed? Only the desk sees this.',
    );

    if (reason === null) {
        return;
    }

    router.delete(ModerationController.remove([eventId.value, messageId]).url, {
        data: { reason },
        preserveScroll: true,
    });
}

function resolveReport(
    reportId: string,
    state: 'resolved' | 'dismissed',
): void {
    router.post(
        ModerationController.resolve([eventId.value, reportId]).url,
        { state },
        { preserveScroll: true },
    );
}

function lock(): void {
    const reason = window.prompt(
        'Lock the wall? Everything stays readable; nobody can post. Optional note for the desk:',
        '',
    );

    if (reason === null) {
        return;
    }

    router.post(
        ModerationController.lock(eventId.value).url,
        { reason: reason === '' ? null : reason },
        { preserveScroll: true },
    );
}

function unlock(): void {
    router.delete(ModerationController.unlock(eventId.value).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Shoutbox" />

    <div class="flex max-w-4xl flex-col gap-6">
        <AdminPageHeader
            title="Shoutbox"
            description="Reports, mutes and the lock switch for this event's wall"
        >
            <Link
                :href="showEvent(eventId).url"
                class="text-sm text-text-muted hover:text-text"
            >
                Dashboard
            </Link>
        </AdminPageHeader>

        <p v-if="room === null" class="text-sm text-text-muted" role="status">
            Nobody has opened this event's wall yet. It appears here once the
            first admitted participant does.
        </p>

        <template v-else>
            <InputError :message="errors.reason" />

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Wall</CardTitle></CardHeader>
                <CardContent class="flex flex-wrap items-center gap-3">
                    <template v-if="room.closedAt">
                        <Badge variant="secondary">Closed</Badge>
                        <span class="text-sm text-text-muted">
                            The event has concluded ({{
                                formatDateTime(room.closedAt)
                            }}); the wall is read-only for good.
                        </span>
                    </template>
                    <template v-else-if="room.lockedAt">
                        <Badge variant="destructive">Locked</Badge>
                        <span class="text-sm text-text-muted">
                            Since {{ formatDateTime(room.lockedAt)
                            }}<template v-if="room.lockedReason"
                                >: {{ room.lockedReason }}</template
                            >
                        </span>
                        <Button size="sm" variant="secondary" @click="unlock">
                            Unlock
                        </Button>
                    </template>
                    <template v-else>
                        <Badge>Open</Badge>
                        <Button size="sm" variant="secondary" @click="lock">
                            Lock the wall
                        </Button>
                    </template>
                </CardContent>
            </Card>

            <Card v-if="muteTarget" class="border-border bg-surface">
                <CardHeader>
                    <CardTitle>Mute {{ muteTarget.name }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="submitMute"
                    >
                        <div class="grid gap-2">
                            <Label for="mute-minutes">Duration</Label>
                            <select
                                id="mute-minutes"
                                v-model="muteForm.minutes"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                            >
                                <option
                                    v-for="duration in durations"
                                    :key="duration.label"
                                    :value="duration.minutes"
                                >
                                    {{ duration.label }}
                                </option>
                            </select>
                            <InputError :message="muteForm.errors.minutes" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="mute-reason">
                                Reason — shown to the participant
                            </Label>
                            <input
                                id="mute-reason"
                                v-model="muteForm.reason"
                                type="text"
                                :maxlength="desk.muteReasonMaxLength"
                                required
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm text-text"
                            />
                            <InputError :message="muteForm.errors.reason" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Button
                                type="submit"
                                :disabled="muteForm.processing"
                            >
                                Mute
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                @click="closeMute"
                            >
                                Cancel
                            </Button>
                            <span
                                v-if="muteForm.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                Unsaved changes
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Open reports</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <p
                        v-if="desk.reports.length === 0"
                        class="text-sm text-text-muted"
                    >
                        Nothing reported.
                    </p>
                    <div
                        v-for="report in desk.reports"
                        :key="report.id"
                        class="flex flex-col gap-2 border-b border-border pb-4 last:border-b-0 last:pb-0"
                    >
                        <p class="text-sm text-text">
                            <span class="font-medium"
                                >{{
                                    report.authorDisplayName ??
                                    'Unknown participant'
                                }}:</span
                            >
                            <span
                                :class="
                                    report.removedAt
                                        ? 'text-text-muted line-through'
                                        : ''
                                "
                            >
                                {{ report.body }}
                            </span>
                        </p>
                        <p class="text-xs text-text-muted">
                            Reported by
                            {{
                                report.reporterDisplayName ??
                                'an unknown participant'
                            }}
                            · {{ formatDateTime(report.reportedAt) }}
                            <template v-if="report.note">
                                · “{{ report.note }}”</template
                            >
                            <template v-if="report.removedAt">
                                · removed</template
                            >
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="!report.removedAt"
                                size="sm"
                                variant="destructive"
                                @click="removeLine(report.messageId)"
                            >
                                Remove line
                            </Button>
                            <Button
                                size="sm"
                                variant="secondary"
                                @click="
                                    openMute(
                                        report.authorUserId,
                                        report.authorDisplayName,
                                    )
                                "
                            >
                                Mute author
                            </Button>
                            <Button
                                size="sm"
                                variant="secondary"
                                @click="resolveReport(report.id, 'resolved')"
                            >
                                Resolve
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="resolveReport(report.id, 'dismissed')"
                            >
                                Dismiss
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Muted</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="desk.mutes.length === 0"
                        class="text-sm text-text-muted"
                    >
                        Nobody is muted.
                    </p>
                    <div
                        v-for="mute in desk.mutes"
                        :key="mute.userId"
                        class="flex flex-wrap items-center gap-3 text-sm"
                    >
                        <span class="font-medium text-text">{{
                            mute.displayName ?? 'Unknown participant'
                        }}</span>
                        <span class="text-text-muted">
                            {{ mute.reason }} ·
                            <template v-if="mute.expiresAt"
                                >until
                                {{ formatDateTime(mute.expiresAt) }}</template
                            >
                            <template v-else
                                >for the rest of the event</template
                            >
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="ml-auto"
                            @click="unmute(mute.userId)"
                        >
                            Lift
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-border bg-surface">
                <CardHeader><CardTitle>Latest lines</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="desk.lines.length === 0"
                        class="text-sm text-text-muted"
                    >
                        Nothing said yet.
                    </p>
                    <div
                        v-for="line in desk.lines"
                        :key="line.id"
                        class="flex flex-wrap items-start gap-3 text-sm"
                    >
                        <span class="shrink-0 text-xs text-text-muted">{{
                            formatDateTime(line.postedAt)
                        }}</span>
                        <span class="font-medium text-text">{{
                            line.authorDisplayName ?? 'Unknown participant'
                        }}</span>
                        <span
                            class="min-w-0 flex-1 break-words"
                            :class="
                                line.removedAt
                                    ? 'text-text-muted line-through'
                                    : 'text-text'
                            "
                        >
                            {{ line.body }}
                            <template v-if="line.removedAt">
                                (removed: {{ line.removedReason }})</template
                            >
                        </span>
                        <div class="flex gap-2">
                            <Button
                                v-if="!line.removedAt"
                                size="sm"
                                variant="ghost"
                                @click="removeLine(line.id)"
                            >
                                Remove
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    openMute(
                                        line.authorUserId,
                                        line.authorDisplayName,
                                    )
                                "
                            >
                                Mute
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
