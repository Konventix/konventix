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
import { checkOut } from '@/routes/admin/tickets';

/**
 * The console check-out (attendee-list design §4.3): a confirmation, then
 * one POST. Offered while the ticket is in today; the server refuses it
 * otherwise, and that refusal is shown here as a sentence.
 */
const props = defineProps<{
    open: boolean;
    ticket: {
        admissionId: string;
        reference: string;
        holderName: string | null;
        canCheckOut: boolean;
    };
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const form = useForm({});

const refusal = computed(
    () => (form.errors as Record<string, string>).check_in,
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            form.clearErrors();
        }
    },
);

function submit(): void {
    form.post(checkOut(props.ticket.admissionId).url, {
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
                <DialogTitle>
                    {{
                        $t('Check out ticket :reference', {
                            reference: ticket.reference,
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            ':guest is recorded as having left, as if scanned out at the door.',
                            { guest: ticket.holderName ?? $t('This guest') },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputError :message="refusal" />
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !ticket.canCheckOut"
                    >
                        {{ $t('Check out') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
