<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, watch } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { toInstant } from '@/lib/datetime';
import {
    index as competitionsIndex,
    store as storeCompetition,
} from '@/routes/admin/competitions';

/**
 * A new competition (design §9) — and its first stage in the same form,
 * because the format lives on the stage (§7.1) and a competition without one
 * has nowhere to keep it.
 *
 * It asks for identity, the field and the shape of the contest. The check-in
 * and result knobs start at their safe defaults — no check-in, desk-only
 * results, no proofs — and are edited on the competition's own screen once
 * it exists. Everything here is a draft: publishing is a lifecycle
 * transition with its own button and its own event (§4), never a checkbox on
 * a create form.
 *
 * The three moments are typed in the reader's own zone and sent as instants
 * (`toInstant`), because the columns store absolute moments (.ai/rules/js.md).
 */
const props = defineProps<{
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    disciplines: { id: string; name: string; brandId: string; teamSize: number }[];
    options: { stageFormat: string[] };
    selection: { brandId: string | null; eventId: string | null };
}>();

const form = useForm({
    brand_id: props.selection.brandId ?? props.brands[0]?.id ?? '',
    eventId: props.selection.eventId ?? '',
    disciplineId: '',
    name: '',
    slug: '',
    description: null as string | null,
    rules: null as string | null,
    maxEntrants: null as number | null,
    signupOpensAt: '',
    signupClosesAt: '',
    startsAt: '',
    stageName: 'Playoffs',
    stageFormat: props.options.stageFormat[0] ?? 'single_elimination',
    stageSettings: null as Record<string, unknown> | null,
    checkInMode: 'none',
    checkInOpensMinutesBefore: 60,
    requiresEventCheckIn: false,
    resultSubmissionMode: 'desk_only',
    proofPolicy: 'none',
});

useUnsavedChanges(() => form.isDirty);

const eventsForBrand = computed(() =>
    props.events.filter((event) => event.brandId === form.brand_id),
);

const disciplinesForBrand = computed(() =>
    props.disciplines.filter(
        (discipline) => discipline.brandId === form.brand_id,
    ),
);

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

watch(
    () => form.name,
    (name) => {
        form.slug = slugify(name);
    },
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        signupOpensAt: toInstant(data.signupOpensAt),
        signupClosesAt: toInstant(data.signupClosesAt),
        startsAt: toInstant(data.startsAt),
    })).post(storeCompetition().url);
}
</script>

<template>
    <Head title="New competition" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="New competition"
            description="One contest, its discipline and its first stage"
        >
            <Button variant="outline" as-child>
                <Link :href="competitionsIndex().url">
                    <ArrowLeft class="size-4" />
                    All competitions
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Identity</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="competition-brand">Brand</Label>
                            <select
                                id="competition-brand"
                                v-model="form.brand_id"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="brand in props.brands"
                                    :key="brand.id"
                                    :value="brand.id"
                                >
                                    {{ brand.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.brand_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-event">Event</Label>
                            <select
                                id="competition-event"
                                v-model="form.eventId"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Choose an event
                                </option>
                                <option
                                    v-for="event in eventsForBrand"
                                    :key="event.id"
                                    :value="event.id"
                                >
                                    {{ event.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.eventId" />
                        </div>
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
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="competition-discipline">
                                Discipline
                            </Label>
                            <select
                                id="competition-discipline"
                                v-model="form.disciplineId"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Choose a discipline
                                </option>
                                <option
                                    v-for="discipline in disciplinesForBrand"
                                    :key="discipline.id"
                                    :value="discipline.id"
                                >
                                    {{ discipline.name }} ({{
                                        discipline.teamSize
                                    }}
                                    per team)
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                Team size, duration hint and draws come from
                                the discipline.
                            </p>
                            <InputError :message="form.errors.disciplineId" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>When</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            Leave a signup bound empty for no bound. While the
                            competition is published, the window alone decides
                            whether anybody can enter.
                        </p>

                        <DateTimeField
                            id="competition-signup-opens"
                            v-model="form.signupOpensAt"
                            label="Signup opens"
                            :error="form.errors.signupOpensAt"
                        />
                        <DateTimeField
                            id="competition-signup-closes"
                            v-model="form.signupClosesAt"
                            label="Signup closes"
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
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="value in props.options.stageFormat"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value.replace(/_/g, ' ') }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                The format belongs to the stage; a later
                                group-into-playoff competition adds a second
                                one.
                            </p>
                            <InputError :message="form.errors.stageFormat" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="competition-max">Participant limit</Label>
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

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Create draft
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
