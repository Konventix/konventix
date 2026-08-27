<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Unplug } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    disconnect as disconnectAccount,
    label as updateLabel,
} from '@/routes/admin/settlement-accounts';

/**
 * One of a brand's live settlement accounts (ADR-0046): its checkout
 * label, its offered state, and disconnect. The settlement-accounts
 * console repeats this once per brand per live account, instead of the
 * single "the account" the page used to show.
 */
export type SettlementAccount = {
    id: string;
    provider: string;
    label: string | null;
    checkoutLabel: string | null;
    providerAccountId: string | null;
    connectedAt: string | null;
    pendingOnboarding: boolean;
    offered: boolean;
    capabilitiesCheckedAt: string | null;
};

const props = defineProps<{ account: SettlementAccount }>();

const providerNames: Record<string, string> = {
    stripe: 'Stripe',
    mollie: 'Mollie',
    unzer: 'Unzer',
};

const labelForm = useForm({
    checkoutLabel: props.account.checkoutLabel ?? '',
});

useUnsavedChanges(() => labelForm.isDirty);

function saveLabel(): void {
    labelForm.put(updateLabel(props.account.id).url, { preserveScroll: true });
}

function disconnect(): void {
    if (
        confirm(
            'Disconnect this account? The brand falls back to manual booking until a new one is connected.',
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

function offeredStatus(account: SettlementAccount): OfferedStatus {
    if (account.pendingOnboarding) {
        return { text: 'Onboarding pending', variant: 'destructive' };
    }

    if (account.offered) {
        return { text: 'Offered at checkout', variant: 'default' };
    }

    return {
        text: `Connected — not yet active at ${providerNames[account.provider] ?? account.provider}`,
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
                connected {{ account.connectedAt }}
            </span>
        </div>

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="saveLabel"
        >
            <div class="flex flex-col gap-2">
                <Label :for="`checkout-label-${account.id}`">
                    Checkout label
                </Label>
                <Input
                    :id="`checkout-label-${account.id}`"
                    v-model="labelForm.checkoutLabel"
                    placeholder="Card payment"
                    class="max-w-xs"
                />
                <InputError :message="labelForm.errors.checkoutLabel" />
            </div>
            <Button type="submit" size="sm" :disabled="labelForm.processing">
                Save label
            </Button>
            <span
                v-if="labelForm.isDirty"
                role="status"
                class="text-sm text-warning"
            >
                Unsaved changes
            </span>
        </form>

        <Button
            variant="outline"
            size="sm"
            class="self-start"
            @click="disconnect"
        >
            <Unplug class="size-4" />
            Disconnect
        </Button>
    </div>
</template>
