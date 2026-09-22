<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime, toInstant, toLocalDateTimeInput } from '@/lib/datetime';
import {
    index as competitionsIndex,
    transition as transitionCompetition,
    update as updateCompetition,
} from '@/routes/admin/competitions';

/**
 * One competition and its first stage (design §7.1, §9), plus the lifecycle
 * an organizer drives it through (§4).
 *
 * The lifecycle is buttons, not a field: each one posts the state it moves
 * to, and `CompetitionStatus`'s transition table decides whether that move
 * exists at all — a stale page offering "start" gets a field error back
 * rather than a silent write. Starting additionally needs a drawn bracket
 * and no incomplete roster; the server says which one it was.
 *
 * The format of a drawn bracket is frozen: the matches that exist have its
 * shape, and a redraw is a deliberate act of its own.
 */
type Props = {
    competition: Konventix.Competition.Data.CompetitionData & {
        id: string;
        brandName: string;
        status: string;
        publishedAt: string | null;
        stageGenerated: boolean;
        entrants: number;
    };
    events: { id: string; name: string; brandId: string }[];
    disciplines: { id: string; name: string; brandId: string; teamSize: number }[];
    options: {
        stageFormat: string[];
        checkInMode: string[];
        resultSubmissionMode: string[];
        proofPolicy: string[];
    };
    transitions: { status: string; label: string }[];
};

const props = defineProps<Props>();

const form = useForm({
    eventId: props.competition.eventId,
    disciplineId: props.competition.disciplineId,
    name: props.competition.name,
    slug: props.competition.slug,
    description: props.competition.description ?? '',
    rules: props.competition.rules ?? '',
    maxEntrants: props.competition.maxEntrants,
    signupOpensAt: toLocalDateTimeInput(props.competition.signupOpensAt),
    signupClosesAt: toLocalDateTimeInput(props.competition.signupClosesAt),
    startsAt: toLocalDateTimeInput(props.competition.startsAt),
    stageName: props.competition.stageName,
    stageFormat: props.competition.stageFormat,
    stageSettings: props.competition.stageSettings,
    checkInMode: props.competition.checkInMode,
    checkInOpensMinutesBefore: props.competition.checkInOpensMinutesBefore,
    requiresEventCheckIn: props.competition.requiresEventCheckIn,
    resultSubmissionMode: props.competition.resultSubmissionMode,
    proofPolicy: props.competition.proofPolicy,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.transform((data) => ({
        ...data,
        description: data.description === '' ? null : data.description,
        rules: data.rules === '' ? null : data.rules,
        signupOpensAt: toInstant(data.signupOpensAt),
        signupClosesAt: toInstant(data.signupClosesAt),
        startsAt: toInstant(data.startsAt),
    })).put(updateCompetition(props.competition.id).url, {
        preserveScroll: true,
    });
}

/**
 * The lifecycle posts on its own rather than through `form`, so its refusal
 * ("you cannot start from here", "no bracket drawn yet") arrives outside the
 * form's error bag and is held here for the message below the buttons.
 */
const transitionError = ref<string | undefined>(undefined);

function move(status: string): void {
    transitionError.value = undefined;

    router.post(
        transitionCompetition(props.competition.id).url,
        { status },
        {
            preserveScroll: true,
            onError: (errors) => {
                transitionError.value = errors.status;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${props.competition.name} — Competition`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.competition.name"
            :description="props.competition.brandName"
        >
            <span
                class="inline-flex rounded-full bg-subtle px-2 py-0.5 text-xs font-medium text-text-muted"
            >
                {{ props.competition.status.replace('_', ' ') }}
            </span>
            <Button variant="outline" as-child>
                <Link :href="competitionsIndex().url">
                    <ArrowLeft class="size-4" />
                    All competitions
                </Link>
            </Button>
        </AdminPageHeader>

        <Card>
            <CardHeader>
                <CardTitle>Lifecycle</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p class="text-sm text-text-muted">
                    {{ props.competition.entrants }} entrant(s), incomplete
                    rosters included.
                    <template v-if="props.competition.publishedAt">
                        Published
                        {{ formatDateTime(props.competition.publishedAt) }}.
                    </template>
                    <template v-if="!props.competition.stageGenerated">
                        The bracket has not been drawn yet, so it cannot start.
                    </template>
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="option in props.transitions"
                        :key="option.status"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="move(option.status)"
                    >
                        {{ option.label }}
                    </Button>
                    <span
                        v-if="props.transitions.length === 0"
                        class="text-sm text-text-muted"
                    >
                        This competition is history — nothing moves from here.
                    </span>
                </div>
                <InputError :message="transitionError" />
            </CardContent>
        </Card>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Identity</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="competition-name">Name</Label>
                            <Input id="competition-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-slug">Slug</Label>
                            <Input id="competition-slug" v-model="form.slug" />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-event">Event</Label>
                            <select
                                id="competition-event"
                                v-model="form.eventId"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="event in props.events"
                                    :key="event.id"
                                    :value="event.id"
                                >
                                    {{ event.name }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                Frozen once teams have entered — teams belong
                                to one event.
                            </p>
                            <InputError :message="form.errors.eventId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-discipline">
                                Discipline
                            </Label>
                            <select
                                id="competition-discipline"
                                v-model="form.disciplineId"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="discipline in props.disciplines"
                                    :key="discipline.id"
                                    :value="discipline.id"
                                >
                                    {{ discipline.name }} ({{
                                        discipline.teamSize
                                    }}
                                    per team)
                                </option>
                            </select>
                            <InputError :message="form.errors.disciplineId" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="competition-description">
                                Description
                            </Label>
                            <textarea
                                id="competition-description"
                                v-model="form.description"
                                rows="4"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <p class="text-xs text-text-muted">
                                Markdown. Shown on the public competition page.
                            </p>
                            <InputError :message="form.errors.description" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="competition-rules">Rules</Label>
                            <textarea
                                id="competition-rules"
                                v-model="form.rules"
                                rows="6"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <InputError :message="form.errors.rules" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>When</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <DateTimeField
                            id="competition-signup-opens"
                            v-model="form.signupOpensAt"
                            label="Signup opens"
                            hint="Empty means no lower bound."
                            :error="form.errors.signupOpensAt"
                        />
                        <DateTimeField
                            id="competition-signup-closes"
                            v-model="form.signupClosesAt"
                            label="Signup closes"
                            hint="Empty means no upper bound; closing signup by hand works regardless."
                            :error="form.errors.signupClosesAt"
                        />
                        <DateTimeField
                            id="competition-starts"
                            v-model="form.startsAt"
                            label="Starts"
                            hint="What the published programme shows as this contest's start."
                            :error="form.errors.startsAt"
                        />
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>First stage</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="stage-name">Stage name</Label>
                            <Input id="stage-name" v-model="form.stageName" />
                            <InputError :message="form.errors.stageName" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="stage-format">Format</Label>
                            <select
                                id="stage-format"
                                v-model="form.stageFormat"
                                :disabled="props.competition.stageGenerated"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                            >
                                <option
                                    v-for="value in props.options.stageFormat"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value.replace(/_/g, ' ') }}
                                </option>
                            </select>
                            <p
                                v-if="props.competition.stageGenerated"
                                class="text-xs text-text-muted"
                            >
                                The bracket is drawn; its format is frozen.
                            </p>
                            <InputError :message="form.errors.stageFormat" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-max">
                                Participant limit
                            </Label>
                            <Input
                                id="competition-max"
                                v-model.number="form.maxEntrants"
                                type="number"
                                min="2"
                                placeholder="No limit"
                            />
                            <InputError :message="form.errors.maxEntrants" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Check-in</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="check-in-mode">Mode</Label>
                            <select
                                id="check-in-mode"
                                v-model="form.checkInMode"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="value in props.options.checkInMode"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value.replace('_', ' ') }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                None means every registered entrant counts as
                                ready. The desk can always check a team in.
                            </p>
                            <InputError :message="form.errors.checkInMode" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="check-in-minutes">
                                Self-service opens (minutes before start)
                            </Label>
                            <Input
                                id="check-in-minutes"
                                v-model.number="form.checkInOpensMinutesBefore"
                                type="number"
                                min="0"
                            />
                            <InputError
                                :message="form.errors.checkInOpensMinutesBefore"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.requiresEventCheckIn"
                                type="checkbox"
                                class="size-4 rounded border-border"
                            />
                            Every roster member must be through the event door
                        </label>
                        <InputError
                            :message="form.errors.requiresEventCheckIn"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Results</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="result-mode">Who reports</Label>
                            <select
                                id="result-mode"
                                v-model="form.resultSubmissionMode"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="value in props.options
                                        .resultSubmissionMode"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value.replace('_', ' ') }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                The desk can always record, whatever this says.
                            </p>
                            <InputError
                                :message="form.errors.resultSubmissionMode"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="proof-policy">Proof</Label>
                            <select
                                id="proof-policy"
                                v-model="form.proofPolicy"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="value in props.options.proofPolicy"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value }}
                                </option>
                            </select>
                            <InputError :message="form.errors.proofPolicy" />
                        </div>
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </div>
        </form>
    </div>
</template>
