<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * Which declarations this type demands (ADR-0066 §9.1, D-REG-18/19), and the
 * type it offers instead when somebody will not make one (D-REG-21).
 *
 * The card stays editable after the type has sold — `entryConditions` is
 * deliberately **not** one of `GuardsTypeConfiguration::frozenAxes()`
 * (D-REG-19), because the evidence is the snapshot on each assertion and not
 * this list. What it owes in return is the warning: on a type that has
 * registrations, it says before saving how many of them a newly added
 * condition leaves *not asserted*, and how many historic declarations a
 * removal leaves standing (spec §4.2).
 *
 * The assertion text is shown inline with every option on purpose: an
 * operator ticking "Student ticket" is choosing a sentence somebody will be
 * asked to agree to, and a name alone hides exactly that.
 */
const props = defineProps<{
    modelValue: string[];
    conditions: {
        id: string;
        key: string;
        name: string;
        assertionText: string;
        verification: string;
        archived: boolean;
    }[];
    /** How many assertions each condition already carries for this type. */
    assertionCounts: Record<string, number>;
    /** The set as it was saved — what "added" and "removed" are measured against. */
    savedIds: string[];
    registrations: number;
    alternativeTypeId: string | null;
    alternativeTypes: { id: string; name: string }[];
    error?: string;
    alternativeError?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [string[]];
    'update:alternativeTypeId': [string | null];
}>();

function toggle(conditionId: string, checked: boolean): void {
    emit(
        'update:modelValue',
        checked
            ? [...new Set([...props.modelValue, conditionId])]
            : props.modelValue.filter((id) => id !== conditionId),
    );
}

const added = computed(() =>
    props.conditions.filter(
        (condition) =>
            props.modelValue.includes(condition.id) &&
            !props.savedIds.includes(condition.id),
    ),
);

const removed = computed(() =>
    props.conditions.filter(
        (condition) =>
            !props.modelValue.includes(condition.id) &&
            props.savedIds.includes(condition.id),
    ),
);

/** Only a type that has sold has anything to warn about (spec §4.2). */
const warns = computed(
    () =>
        props.registrations > 0 &&
        (added.value.length > 0 || removed.value.length > 0),
);

const strandedAssertions = computed(() =>
    removed.value.reduce(
        (total, condition) =>
            total + (props.assertionCounts[condition.id] ?? 0),
        0,
    ),
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Entry conditions</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <p class="text-sm text-text-muted">
                What a registrant has to declare before they may buy this type.
                Each one becomes a required checkbox on the registration form,
                carrying its sentence word for word. This list stays editable
                after the type has sold — nothing already declared is ever
                rewritten.
            </p>

            <p
                v-if="props.conditions.length === 0"
                class="text-sm text-text-muted"
            >
                This brand has no entry conditions yet.
            </p>

            <div
                v-for="condition in props.conditions"
                :key="condition.id"
                class="flex items-start gap-2"
            >
                <Checkbox
                    :id="`entry-condition-${condition.id}`"
                    class="mt-1"
                    :model-value="props.modelValue.includes(condition.id)"
                    @update:model-value="
                        (value: boolean | 'indeterminate') =>
                            toggle(condition.id, value === true)
                    "
                />
                <Label
                    :for="`entry-condition-${condition.id}`"
                    class="grid flex-1 gap-1"
                >
                    <span class="flex items-center gap-2">
                        <span class="font-medium">{{ condition.name }}</span>
                        <span
                            class="rounded-full bg-subtle px-2 py-0.5 text-xs text-text-muted"
                        >
                            {{ condition.verification }}
                        </span>
                        <span
                            v-if="condition.archived"
                            class="rounded-full bg-warning/15 px-2 py-0.5 text-xs text-warning"
                        >
                            archived
                        </span>
                    </span>
                    <span class="text-xs font-normal text-text-muted">
                        {{ condition.assertionText }}
                    </span>
                </Label>
            </div>

            <p
                v-if="warns"
                role="status"
                class="rounded-md border border-warning/30 bg-warning/10 p-3 text-sm text-text"
            >
                <template v-if="added.length > 0">
                    {{ props.registrations }} existing registration(s) will show
                    {{ added.length }} newly added condition(s) as
                    <em>not asserted</em>. Nothing is written for them and
                    nothing is blocked — you can waive each one on the
                    registration.
                </template>
                <template v-if="removed.length > 0">
                    Removing {{ removed.length }} condition(s) leaves
                    {{ strandedAssertions }} existing declaration(s) standing:
                    they stay on the registrations that made them and in their
                    data export.
                </template>
            </p>

            <InputError :message="props.error" />

            <div class="grid gap-2 border-t border-border pt-4">
                <Label for="type-alternative">Alternative type</Label>
                <select
                    id="type-alternative"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    :value="props.alternativeTypeId ?? ''"
                    @change="
                        emit(
                            'update:alternativeTypeId',
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">None</option>
                    <option
                        v-for="type in props.alternativeTypes"
                        :key="type.id"
                        :value="type.id"
                    >
                        {{ type.name }}
                    </option>
                </select>
                <p class="text-xs text-text-muted">
                    Offered as "buy this instead" beside a submission refused at
                    one of these checkboxes. It grants nothing and may be
                    changed at any time.
                </p>
                <InputError :message="props.alternativeError" />
            </div>
        </CardContent>
    </Card>
</template>
