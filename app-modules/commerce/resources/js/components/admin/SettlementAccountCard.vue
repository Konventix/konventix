<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Unplug } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatCalendarDate } from '@/lib/datetime';
import {
    disconnect as disconnectAccount,
    label as updateLabel,
} from '@/routes/admin/settlement-accounts';

/**
 * One of a brand's live settlement accounts (ADR-0046): its checkout
 * label, its statement descriptor suffix, its offered state, and
 * disconnect. The settlement-accounts console repeats this once per brand
 * per live account, instead of the single "the account" the page used to
 * show.
 */
export type SettlementAccount = {
    id: string;
    provider: string;
    label: string | null;
    checkoutLabel: string | null;
    statementDescriptorSuffix: string | null;
    providerAccountId: string | null;
    connectedAt: string | null;
    offered: boolean;
};

const props = defineProps<{
    account: SettlementAccount;
    /**
     * This instance's Stripe account, so a Stripe row that is enabled but not
     * offered can say *why* — which of the two keys is missing — instead of
     * always blaming the secret key.
     */
    stripeSetup: Konventix.Commerce.Data.StripeSetupData;
}>();

const providerNames: Record<string, string> = {
    stripe: 'Stripe',
    mollie: 'Mollie',
    unzer: 'Unzer',
};

const labelForm = useForm({
    checkoutLabel: props.account.checkoutLabel ?? '',
    // Stripe only: what the payer reads on their card statement (ADR-0058
    // point 3). Correctable here rather than by disconnecting, because
    // `connectStripe()` refuses a second live Stripe row per brand.
    statementDescriptorSuffix: props.account.statementDescriptorSuffix ?? '',
});

useUnsavedChanges(() => labelForm.isDirty);

function saveLabel(): void {
    labelForm.put(updateLabel(props.account.id).url, { preserveScroll: true });
}

function disconnect(): void {
    if (
        confirm(
            trans(
                'Disconnect this account? The brand falls back to manual booking until a new one is connected.',
            ),
        )
    ) {
        router.post(
            disconnectAccount(props.account.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

type OfferedStatus = {
    text: string;
    variant: 'default' | 'secondary' | 'destructive';
};

/**
 * A Stripe row is an enablement flag on the account this instance holds
 * (ADR-0058 point 3), so "enabled but not offered" means one thing only:
 * this instance cannot charge yet — which takes the secret key *and* the
 * webhook signing secret (ADR-0039 point 2), and which the Stripe card above
 * says how to fix. Naming the missing half here saves the operator the trip.
 * Mollie and Unzer answer for themselves as before.
 */
function offeredStatus(account: SettlementAccount): OfferedStatus {
    if (account.offered) {
        return { text: trans('Offered at checkout'), variant: 'default' };
    }

    if (account.provider === 'stripe') {
        return {
            text: props.stripeSetup.secretKeySet
                ? trans(
                      'Enabled — no Stripe signing secret on this instance yet',
                  )
                : trans('Enabled — no Stripe key on this instance yet'),
            variant: 'destructive',
        };
    }

    return {
        text: trans('Connected — not yet active at :provider', {
            provider: providerNames[account.provider] ?? account.provider,
        }),
        variant: 'secondary',
    };
}
</script>

<template>
    <div class="flex flex-col gap-3 rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Badge variant="secondary">
                {{ providerNames[account.provider] ?? account.provider }}
            </Badge>
            <Badge :variant="offeredStatus(account).variant">
                {{ offeredStatus(account).text }}
            </Badge>
            <span
                v-if="account.providerAccountId"
                class="font-mono text-xs text-text-muted"
            >
                {{ account.providerAccountId }}
            </span>
            <span v-if="account.connectedAt" class="text-xs text-text-muted">
                {{
                    $t('connected :date', {
                        date: formatCalendarDate(account.connectedAt),
                    })
                }}
            </span>
            <span
                v-if="account.statementDescriptorSuffix"
                class="font-mono text-xs text-text-muted"
            >
                {{
                    $t('statement: :suffix', {
                        suffix: account.statementDescriptorSuffix,
                    })
                }}
            </span>
        </div>

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="saveLabel"
        >
            <div class="flex flex-col gap-2">
                <Label :for="`checkout-label-${account.id}`">
                    {{ $t('Checkout label') }}
                </Label>
                <Input
                    :id="`checkout-label-${account.id}`"
                    v-model="labelForm.checkoutLabel"
                    :placeholder="$t('Card payment')"
                    class="max-w-xs"
                    :aria-invalid="
                        labelForm.errors.checkoutLabel ? true : undefined
                    "
                    :aria-describedby="
                        labelForm.errors.checkoutLabel
                            ? `checkout-label-${account.id}-error`
                            : undefined
                    "
                />
                <InputError
                    :id="`checkout-label-${account.id}-error`"
                    :message="labelForm.errors.checkoutLabel"
                />
            </div>
            <div
                v-if="account.provider === 'stripe'"
                class="flex flex-col gap-2"
            >
                <Label :for="`statement-suffix-${account.id}`">
                    {{ $t('Statement descriptor suffix') }}
                </Label>
                <Input
                    :id="`statement-suffix-${account.id}`"
                    v-model="labelForm.statementDescriptorSuffix"
                    :placeholder="'SXLAN'"
                    maxlength="10"
                    autocomplete="off"
                    class="max-w-xs"
                    :aria-invalid="
                        labelForm.errors.statementDescriptorSuffix
                            ? true
                            : undefined
                    "
                    :aria-describedby="`statement-suffix-${account.id}-hint${labelForm.errors.statementDescriptorSuffix ? ` statement-suffix-${account.id}-error` : ''}`"
                />
                <InputError
                    :id="`statement-suffix-${account.id}-error`"
                    :message="labelForm.errors.statementDescriptorSuffix"
                />
                <p
                    :id="`statement-suffix-${account.id}-hint`"
                    class="max-w-xs text-xs text-text-muted"
                >
                    {{
                        $t(
                            'Stripe prepends the account’s own prefix and caps the two together at 22 characters. Leave empty to use the account’s own descriptor.',
                        )
                    }}
                </p>
            </div>
            <Button type="submit" size="sm" :disabled="labelForm.processing">
                {{ $t('Save') }}
            </Button>
            <span
                v-if="labelForm.isDirty"
                role="status"
                class="text-sm text-warning"
            >
                {{ $t('Unsaved changes') }}
            </span>
        </form>

        <Button
            variant="outline"
            size="sm"
            class="self-start"
            @click="disconnect"
        >
            <Unplug class="size-4" />
            {{ $t('Disconnect') }}
        </Button>
    </div>
</template>
