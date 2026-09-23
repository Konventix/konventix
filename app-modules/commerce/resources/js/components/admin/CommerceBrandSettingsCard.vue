<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update } from '@/routes/admin/brands/commerce-settings';

/**
 * The brand's repayment window (ADR-0057 point 4), rendered inside that
 * brand's card on the settlement-accounts page — the money that was
 * reversed left through one of the accounts listed above it, and this is
 * how long the payer has to put it back before
 * `commerce:cancel-unpaid-reversals` cancels the order.
 *
 * Its own `useForm` and its own ADR-0038 dirty guard, independent of the
 * connect form on the same page, exactly as `WaitingRoomSettingsCard` owns
 * its own beside the brand edit page's other cards.
 */
type Settings = Konventix.Commerce.Data.CommerceBrandSettingsData;

const props = defineProps<{
    brandId: string;
    settings: Settings;
}>();

const form = useForm({
    reversalRepaymentDays: props.settings.reversalRepaymentDays,
});

useUnsavedChanges(() => form.isDirty);

const fieldId = `commerce-repayment-days-${props.brandId}`;
const errorId = `${fieldId}-error`;

function submit(): void {
    form.put(update(props.brandId).url, { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-3" @submit.prevent="submit">
        <p class="text-sm text-text-muted">
            {{
                $t(
                    'How many days a payer has to put back money that was reversed — a returned direct debit or a lost chargeback — before the order is cancelled. An order whose admission was already used is never cancelled; it becomes an open receivable instead.',
                )
            }}
        </p>

        <div class="grid max-w-xs gap-2">
            <Label :for="fieldId">{{ $t('Repayment window (days)') }}</Label>
            <Input
                :id="fieldId"
                v-model.number="form.reversalRepaymentDays"
                type="number"
                min="1"
                max="60"
                :aria-invalid="
                    form.errors.reversalRepaymentDays ? true : undefined
                "
                :aria-describedby="
                    form.errors.reversalRepaymentDays ? errorId : undefined
                "
            />
            <InputError
                :id="errorId"
                :message="form.errors.reversalRepaymentDays"
            />
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" variant="outline" :disabled="form.processing">
                {{ $t('Save repayment window') }}
            </Button>
            <span
                v-if="form.isDirty"
                role="status"
                class="text-sm text-warning"
            >
                {{ $t('Unsaved changes') }}
            </span>
        </div>
    </form>
</template>
