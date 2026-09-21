<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { reduceQuantity } from '@/routes/admin/registrations';
import type { RegistrationRow } from './rows';

/**
 * D-REG-15's partial cancellation, from the registration list's own row.
 *
 * Extracted rather than written inline: `pages/admin/registrations/Index.vue`
 * was already at the ~300-line limit CLAUDE_extra sets, and a form with its
 * own `useForm`, its own unsaved-changes scope and its own refusal handling
 * is a component, not a page section.
 *
 * Every refusal arrives as a validation error on `units` — the Action's own
 * guards ("not confirmed", "that would leave nothing"), Admission's
 * ("only two admissions are still free"), and Commerce's per-admission
 * check-in guard. They all name a number the operator can act on, which is
 * why they share one field rather than a banner.
 */
const props = defineProps<{
    /** Null closes the dialog; a row opens it for that registration. */
    registration: RegistrationRow | null;
    reasons: string[];
}>();

const emit = defineEmits<{ close: [] }>();

const open = computed(() => props.registration !== null);

/**
 * Deliberately no pre-picked reason, the same reasoning the order refund
 * dialog states: an irreversible money movement must not be justified by an
 * opinion nobody formed, and an untouched form stays `isDirty === false` so
 * ADR-0038's note appears only once something was entered.
 */
const form = useForm<{
    units: number | null;
    reasonCode: string | null;
    reasonNote: string | null;
}>({
    units: null,
    reasonCode: null,
    reasonNote: null,
});

// Only while the dialog is open (ADR-0038): a closed dialog's untouched
// draft is not unsaved work.
useUnsavedChanges(() => open.value && form.isDirty);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

/** One less than the quantity: removing the last unit is a cancellation. */
const maxUnits = computed(() =>
    props.registration ? props.registration.quantity - 1 : 1,
);

function reasonLabel(value: string): string {
    return value
        .split('-')
        .map((part, index) =>
            index === 0 ? part[0].toUpperCase() + part.slice(1) : part,
        )
        .join(' ');
}

function submit(): void {
    if (props.registration === null) {
        return;
    }

    form.post(reduceQuantity(props.registration.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="
            (value: boolean) => {
                if (!value) {
                    emit('close');
                }
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Reduce registration</DialogTitle>
                <DialogDescription>
                    Give back units of
                    {{ registration?.registrant }}'s registration. The quota
                    units return to the waitlist, that many admissions are
                    revoked, and the money for exactly those units is sent back
                    with a credit note. Admissions that already have a holder, a
                    seat or a check-in are never taken — if there are not enough
                    free ones, nothing happens at all.
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="reduce-units"
                        >Units to remove (of
                        {{ registration?.quantity }})</Label
                    >
                    <Input
                        id="reduce-units"
                        v-model.number="form.units"
                        type="number"
                        min="1"
                        :max="maxUnits"
                        :aria-invalid="form.errors.units ? true : undefined"
                        :aria-describedby="
                            form.errors.units ? 'reduce-units-error' : undefined
                        "
                    />
                    <InputError
                        id="reduce-units-error"
                        :message="form.errors.units"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="reduce-reason">Reason</Label>
                    <select
                        id="reduce-reason"
                        v-model="form.reasonCode"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        :aria-invalid="
                            form.errors.reasonCode ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.reasonCode
                                ? 'reduce-reason-error'
                                : undefined
                        "
                    >
                        <!-- Disabled, so it can be read but not chosen. -->
                        <option :value="null" disabled>Choose a reason</option>
                        <option
                            v-for="reason in props.reasons"
                            :key="reason"
                            :value="reason"
                        >
                            {{ reasonLabel(reason) }}
                        </option>
                    </select>
                    <InputError
                        id="reduce-reason-error"
                        :message="form.errors.reasonCode"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="reduce-note">Note (optional)</Label>
                    <textarea
                        id="reduce-note"
                        v-model="form.reasonNote"
                        rows="3"
                        class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                        :aria-invalid="
                            form.errors.reasonNote ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.reasonNote
                                ? 'reduce-note-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="reduce-note-error"
                        :message="form.errors.reasonNote"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Remove units
                    </Button>
                    <span
                        v-if="form.isDirty"
                        class="text-sm text-warning"
                        role="status"
                        >Unsaved changes</span
                    >
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
