<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import RegistrationController from '@/actions/Konventix/Registration/Http/Controllers/RegistrationController';
import EntryConditionCheckbox from './EntryConditionCheckbox.vue';
import {
    entryConditionErrorKey,
    entryConditionsSatisfied,
    toggleEntryCondition,
} from './entryConditions';

/**
 * The declarations a type rests on, as a block of the public form
 * (ADR-0066 §6.2).
 *
 * Extracted from `pages/registration/Register.vue` rather than written into
 * it: that page was near the ~300-line limit CLAUDE_extra sets, and this is
 * a section with state of its own.
 *
 * Every box is required — a reduced fare *is* the declaration, so there is
 * no optional one — and `update:satisfied` is what keeps the submit button
 * honest. The button is a courtesy: `SubmitRegistration::guardEntryConditions()`
 * refuses the same set again under lock, because a disabled control is never
 * the guard.
 *
 * The ticked ids ride on the checkboxes' own `name`: Inertia's `<Form>`
 * reads the DOM and `acceptedEntryConditionIds[]` arrives as a list, so
 * nothing here duplicates the submission.
 *
 * Where the type names an alternative (D-REG-21), a refusal is not a dead
 * end — "buy the regular ticket instead" is right beside it, because a dead
 * end at the checkbox loses a sale the full-price type would have taken.
 */
const props = defineProps<{
    conditions: Konventix.Registration.Data.PublicEntryConditionData[];
    eventId: string;
    alternativeRegistrationTypeId: string | null;
    alternativeRegistrationTypeName: string | null;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:satisfied': [boolean] }>();

const accepted = ref<string[]>([]);

watch(
    accepted,
    (value) =>
        emit(
            'update:satisfied',
            entryConditionsSatisfied(props.conditions, value),
        ),
    { immediate: true },
);

function onToggle(conditionId: string, checked: boolean): void {
    accepted.value = toggleEntryCondition(accepted.value, conditionId, checked);
}
</script>

<template>
    <fieldset
        class="grid gap-4 border-t border-border pt-4"
        data-test="entry-conditions"
    >
        <legend class="text-sm font-medium text-text">
            {{ $t('Please confirm') }}
        </legend>

        <EntryConditionCheckbox
            v-for="condition in props.conditions"
            :key="condition.id"
            :condition="condition"
            name="acceptedEntryConditionIds[]"
            :model-value="accepted.includes(condition.id)"
            :error="props.errors[entryConditionErrorKey(condition.id)]"
            @update:model-value="
                (checked: boolean) => onToggle(condition.id, checked)
            "
        />

        <p
            v-if="props.alternativeRegistrationTypeId"
            class="text-sm text-text-muted"
        >
            {{ $t('Not eligible?') }}
            <Link
                :href="
                    RegistrationController.show.url({
                        event_ulid: props.eventId,
                        type: props.alternativeRegistrationTypeId,
                    })
                "
                class="text-accent hover:underline"
            >
                {{
                    $t('Buy the :type instead', {
                        type: props.alternativeRegistrationTypeName ?? '',
                    })
                }}
            </Link>
        </p>
    </fieldset>
</template>
