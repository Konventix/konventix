<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    archive as archiveCondition,
    index as conditionsIndex,
    update as updateCondition,
} from '@/routes/admin/entry-conditions';
import EntryConditionCheckbox from '../../../components/EntryConditionCheckbox.vue';
import { assertionTextBudget } from '../../../components/entryConditions';

/**
 * One entry condition (ADR-0066 §9.1): the sentence a registrant ticks, the
 * document the door will ask for, and how it is meant to be checked.
 *
 * Two things this screen says out loud, because neither is obvious from the
 * fields:
 *
 * - the **live preview** is the registrant's own checkbox, rendered by the
 *   very component the public form uses, with a character budget beside the
 *   text. A declaration nobody reads is not a declaration, and a
 *   three-paragraph one is a dark pattern rather than a longer sentence.
 * - `operator_checked` is **advisory** (D-REG-22). It fills the worklist on
 *   the registrations list and never gates a confirmation; the axis for "a
 *   human decides before this is confirmed" is `approval: required` on the
 *   type, and two mechanisms for one thing would be one too many.
 *
 * Nothing here is frozen and nothing here is retroactive: a rewording
 * changes what the next registrant is shown, never what a past one declared
 * (that is the snapshot on their own row).
 */
type Props = {
    condition: Konventix.Registration.Data.EntryConditionData & {
        id: string;
        brandName: string;
        assertions: number;
    };
    events: { id: string; name: string }[];
    options: Record<string, string[]>;
    types: { id: string; name: string }[];
};

const props = defineProps<Props>();

const form = useForm({
    eventId: props.condition.eventId,
    key: props.condition.key,
    name: props.condition.name,
    description: props.condition.description,
    assertionText: props.condition.assertionText,
    documentHint: props.condition.documentHint,
    verification: props.condition.verification,
});

useUnsavedChanges(() => form.isDirty);

const budget = computed(() => assertionTextBudget(form.assertionText));

/** The preview is the public component, fed the unsaved wording. */
const preview = computed(() => ({
    id: props.condition.id,
    key: form.key,
    name: form.name,
    assertionText: form.assertionText,
    documentHint: form.documentHint,
}));

function submit(): void {
    form.put(updateCondition(props.condition.id).url, { preserveScroll: true });
}

function archive(): void {
    router.post(
        archiveCondition(props.condition.id).url,
        {},
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head :title="`${props.condition.name} — Entry condition`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.condition.name"
            :description="props.condition.brandName"
        >
            <span
                v-if="props.condition.archived"
                class="inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning"
            >
                archived
            </span>
            <Button variant="outline" as-child>
                <Link :href="conditionsIndex().url">
                    <ArrowLeft class="size-4" />
                    All conditions
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>The condition</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="condition-key">Key</Label>
                                <Input id="condition-key" v-model="form.key" />
                                <p class="text-xs text-text-muted">
                                    Travels to the door and onto the ticket.
                                    Changing it changes what the door reads.
                                </p>
                                <InputError :message="form.errors.key" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="condition-name">Name</Label>
                                <Input
                                    id="condition-name"
                                    v-model="form.name"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="condition-event">Event</Label>
                            <select
                                id="condition-event"
                                v-model="form.eventId"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option :value="null">
                                    Every event of this brand
                                </option>
                                <option
                                    v-for="event in props.events"
                                    :key="event.id"
                                    :value="event.id"
                                >
                                    {{ event.name }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                Leave it on "every event" unless this is a
                                one-off.
                            </p>
                            <InputError :message="form.errors.eventId" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="condition-assertion">
                                What the registrant confirms
                            </Label>
                            <textarea
                                id="condition-assertion"
                                v-model="form.assertionText"
                                rows="3"
                                aria-describedby="condition-assertion-budget"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <p
                                id="condition-assertion-budget"
                                class="text-xs"
                                :class="
                                    budget.overBudget
                                        ? 'text-danger'
                                        : 'text-text-muted'
                                "
                            >
                                {{ budget.length }} of {{ budget.max }}
                                characters. Keep it to one sentence somebody
                                will actually read beside a checkbox.
                            </p>
                            <InputError :message="form.errors.assertionText" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="condition-hint">Document hint</Label>
                            <Input
                                id="condition-hint"
                                v-model="form.documentHint"
                                placeholder="Valid student ID or enrolment certificate."
                            />
                            <p class="text-xs text-text-muted">
                                Shown under the checkbox and printed on the
                                ticket, so nobody arrives without it.
                            </p>
                            <InputError :message="form.errors.documentHint" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="condition-description">
                                Internal note
                            </Label>
                            <textarea
                                id="condition-description"
                                v-model="form.description"
                                rows="2"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <p class="text-xs text-text-muted">
                                For whoever edits a registration type later.
                                Never shown to a registrant.
                            </p>
                            <InputError :message="form.errors.description" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Preview</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p class="text-sm text-text-muted">
                            Exactly what a registrant sees on the form.
                        </p>
                        <EntryConditionCheckbox
                            :condition="preview"
                            :model-value="false"
                            disabled
                        />
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Verification</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <select
                            id="condition-verification"
                            v-model="form.verification"
                            aria-label="Verification"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="value in props.options.verification"
                                :key="value"
                                :value="value"
                            >
                                {{ value }}
                            </option>
                        </select>
                        <p class="text-sm text-text-muted">
                            <template
                                v-if="form.verification === 'self_declared'"
                            >
                                The declaration is the whole check. Nobody asks
                                again.
                            </template>
                            <template
                                v-else-if="
                                    form.verification === 'document_at_door'
                                "
                            >
                                The door asks for the document named above and
                                records confirmed or refused.
                            </template>
                            <template v-else>
                                Advisory only: this fills the "asserted" filter
                                on the registrations list as a worklist. It
                                never blocks a confirmation — for a blocking
                                check set <strong>approval: required</strong> on
                                the registration type.
                            </template>
                        </p>
                        <InputError :message="form.errors.verification" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Where it is used</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <p
                            v-if="props.types.length === 0"
                            class="text-sm text-text-muted"
                        >
                            No registration type demands this condition yet.
                        </p>
                        <ul v-else class="flex flex-col gap-1 text-sm">
                            <li v-for="type in props.types" :key="type.id">
                                {{ type.name }}
                            </li>
                        </ul>
                        <p class="text-xs text-text-muted">
                            {{ props.condition.assertions }} declaration(s) made
                            so far. Archiving keeps every one of them and takes
                            the condition out of the type editor.
                        </p>
                        <Button
                            v-if="!props.condition.archived"
                            type="button"
                            variant="outline"
                            @click="archive"
                        >
                            Archive
                        </Button>
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
