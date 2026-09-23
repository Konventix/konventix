<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { checkIn } from '@/routes/admin/tickets';

type DoorCheck = Konventix.Admission.Data.DoorCheckData;
type Outcome = 'confirmed' | 'refused';

/**
 * The console check-in (attendee-list design §4.3, D-ATT-03, D-ATT-04,
 * ADR-0066): every door check the ticket still owes today, each answered
 * Confirm or Refuse exactly as on the door screen, then one POST. A refusal
 * is recorded and the guest still goes in, as at the door — the dialog says
 * so, and Cancel is how an operator keeps someone out.
 *
 * The server decides. The disabled state here (revoked, already in) is a
 * courtesy; every refusal the server gives — the core's door-equivalent
 * rejection or the console's own — comes back as one sentence under
 * `check_in` and is shown above the form, never as a 500.
 *
 * No unsaved-changes guard: this is a modal action, not an edit form.
 */
const props = defineProps<{
    open: boolean;
    ticket: {
        admissionId: string;
        reference: string;
        state: string;
        holderUserId: string | null;
        holderName: string | null;
        canCheckIn: boolean;
        owedDoorChecks: DoorCheck[];
    };
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm<{
    conditions: Array<{ key: string; outcome: Outcome }>;
}>({ conditions: [] });

const answers = ref<Record<string, Outcome | undefined>>({});

const allAnswered = computed(() =>
    props.ticket.owedDoorChecks.every(
        (check) => answers.value[check.key] !== undefined,
    ),
);

const unavailable = computed(() => {
    if (props.ticket.canCheckIn) {
        return null;
    }

    return props.ticket.state === 'revoked'
        ? 'This ticket is revoked, so it cannot be checked in.'
        : 'This ticket is already checked in today.';
});

/**
 * `check_in` carries every refusal; an answer the server did not accept
 * comes back on its own `conditions.N.key`, whose index is the request's —
 * shown once, like the refund dialog's selection error.
 */
const refusal = computed(() => {
    const errors = form.errors as Record<string, string>;

    if (errors.check_in) {
        return errors.check_in;
    }

    const key = Object.keys(errors).find((name) =>
        name.startsWith('conditions'),
    );

    return key === undefined ? undefined : errors[key];
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            return;
        }

        answers.value = {};
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    if (!props.ticket.canCheckIn || !allAnswered.value) {
        return;
    }

    form.transform(() => ({
        conditions: props.ticket.owedDoorChecks.map((check) => ({
            key: check.key,
            outcome: answers.value[check.key] as Outcome,
        })),
    })).post(checkIn(props.ticket.admissionId).url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Check in ticket {{ ticket.reference }}</DialogTitle
                >
                <DialogDescription>
                    {{
                        ticket.holderName ??
                        (ticket.holderUserId === null
                            ? 'Unassigned ticket'
                            : 'Unnamed holder')
                    }}
                    — checked in as if scanned at the door, with you recorded as
                    the person who let them in.
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex flex-col gap-4"
                data-test="check-in-form"
                @submit.prevent="submit"
            >
                <p
                    v-if="unavailable !== null"
                    role="status"
                    class="text-sm text-text-muted"
                >
                    {{ unavailable }}
                </p>

                <InputError :message="refusal" />

                <p
                    v-if="ticket.holderUserId === null"
                    class="text-sm text-warning"
                >
                    No holder account: the live hub will not open for anyone.
                </p>

                <template v-if="ticket.owedDoorChecks.length > 0">
                    <fieldset
                        v-for="check in ticket.owedDoorChecks"
                        :key="check.key"
                        class="grid gap-2 rounded-md border border-border p-3"
                    >
                        <legend class="px-1 text-sm font-medium">
                            {{ check.label }}
                        </legend>
                        <p
                            v-if="check.documentHint"
                            class="text-sm text-text-muted"
                        >
                            {{ check.documentHint }}
                        </p>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="answers[check.key]"
                                    type="radio"
                                    :name="`door-check-${check.key}`"
                                    value="confirmed"
                                    required
                                />
                                Confirm
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="answers[check.key]"
                                    type="radio"
                                    :name="`door-check-${check.key}`"
                                    value="refused"
                                    required
                                />
                                Refuse
                            </label>
                        </div>
                    </fieldset>
                    <p class="text-sm text-text-muted">
                        Refusing records the refusal; the check-in still goes
                        through, as at the door. Cancel if you are not letting
                        them in.
                    </p>
                </template>

                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            !ticket.canCheckIn ||
                            !allAnswered
                        "
                    >
                        Check in
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
