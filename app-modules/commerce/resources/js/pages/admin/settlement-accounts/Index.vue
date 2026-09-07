<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    mollie as connectMollie,
    stripe as connectStripe,
    unzer as connectUnzer,
} from '@/routes/admin/settlement-accounts';
import CommerceBrandSettingsCard from '../../../components/admin/CommerceBrandSettingsCard.vue';
import SettlementAccountCard from '../../../components/admin/SettlementAccountCard.vue';
import type { SettlementAccount } from '../../../components/admin/SettlementAccountCard.vue';
import StripeAccountCard from '../../../components/admin/StripeAccountCard.vue';

/**
 * Where each brand's money goes (ADR-0007/ADR-0039/ADR-0046). A brand may
 * hold several live accounts at once — one per provider — and the payer
 * chooses which one settles their order at checkout; there is no longer a
 * single answer here. This lists every live account per brand with its
 * offered state and its own checkout label, and below the list, a form to
 * connect whichever providers the brand hasn't connected yet.
 */
type Provider = 'stripe' | 'mollie' | 'unzer';

type Props = {
    brands: {
        id: string;
        name: string;
        accounts: SettlementAccount[];
        connectableProviders: Provider[];
        commerceSettings: Konventix.Commerce.Data.CommerceBrandSettingsData;
    }[];
    stripeSetup: Konventix.Commerce.Data.StripeSetupData;
};

const providerLabels: Record<Provider, string> = {
    stripe: 'Stripe',
    mollie: 'Mollie',
    unzer: 'Unzer',
};

const props = defineProps<Props>();

const connectingBrandId = ref<string | null>(null);

const form = useForm({
    // Overwritten by `transform()` on submit, but declared here so the key
    // exists on the form's type — the server validates `brand_id` and its
    // error had nowhere to land otherwise.
    brand_id: '',
    provider: 'stripe' as Provider,
    contact_email: '',
    api_key: '',
    private_key: '',
});

useUnsavedChanges(() => form.isDirty);

function beginConnect(brand: Props['brands'][number]): void {
    connectingBrandId.value = brand.id;
    form.reset();
    form.clearErrors();
    form.provider = brand.connectableProviders[0] ?? 'stripe';
}

function submitConnect(): void {
    const brandId = connectingBrandId.value;

    if (brandId === null) {
        return;
    }

    const done = {
        preserveScroll: true,
        onSuccess: () => (connectingBrandId.value = null),
    };

    if (form.provider === 'stripe') {
        form.transform((data) => ({
            brand_id: brandId,
            contact_email: data.contact_email,
        })).post(connectStripe().url, done);
    } else if (form.provider === 'mollie') {
        form.transform((data) => ({
            brand_id: brandId,
            api_key: data.api_key,
        })).post(connectMollie().url, done);
    } else {
        form.transform((data) => ({
            brand_id: brandId,
            private_key: data.private_key,
        })).post(connectUnzer().url, done);
    }
}
</script>

<template>
    <Head title="Settlement accounts" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Settlement accounts"
            description="Each brand's own payment-provider connections"
        />

        <p class="max-w-2xl text-sm text-text-muted">
            Money flows directly from the participant into the operator's own
            provider account — the platform never holds funds. A brand may
            connect several providers at once; the payer picks which one settles
            their order at checkout. A brand with no connected account settles
            manually.
        </p>

        <!--
            ADR-0058: Stripe is one account for the whole instance, so its
            keys are set once, above the per-brand list rather than inside a
            brand's connect dialog.
        -->
        <StripeAccountCard :setup="props.stripeSetup" />

        <Card
            v-for="brand in props.brands"
            :key="brand.id"
            class="border-border bg-surface"
        >
            <CardHeader>
                <CardTitle>{{ brand.name }}</CardTitle>
            </CardHeader>

            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="brand.accounts.length === 0"
                    class="text-sm text-text-muted"
                >
                    No connected account — this brand settles manually.
                </p>

                <SettlementAccountCard
                    v-for="account in brand.accounts"
                    :key="account.id"
                    :account="account"
                />

                <template v-if="brand.connectableProviders.length > 0">
                    <template v-if="connectingBrandId === brand.id">
                        <!--
                            Wider on the Stripe branch: the endpoint URL
                            below is a full absolute URL next to a copy
                            button, which does not read in the width two
                            short credential fields need.
                        -->
                        <form
                            class="flex flex-col gap-4"
                            :class="
                                form.provider === 'stripe'
                                    ? 'max-w-2xl'
                                    : 'max-w-md'
                            "
                            @submit.prevent="submitConnect"
                        >
                            <div class="flex flex-col gap-2">
                                <Label for="provider">Provider</Label>
                                <Select v-model="form.provider">
                                    <SelectTrigger id="provider">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="provider in brand.connectableProviders"
                                            :key="provider"
                                            :value="provider"
                                        >
                                            {{ providerLabels[provider] }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                v-if="form.provider === 'stripe'"
                                class="flex flex-col gap-2"
                            >
                                <Label for="contact_email"
                                    >Contact email for the Stripe account</Label
                                >
                                <Input
                                    id="contact_email"
                                    v-model="form.contact_email"
                                    type="email"
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="form.errors.contact_email"
                                />
                                <p class="text-xs text-text-muted">
                                    Connecting opens Stripe's hosted onboarding
                                    for the organizer's own account.
                                </p>
                            </div>

                            <div
                                v-if="form.provider === 'mollie'"
                                class="flex flex-col gap-2"
                            >
                                <Label for="api_key">Mollie API key</Label>
                                <Input
                                    id="api_key"
                                    v-model="form.api_key"
                                    type="password"
                                    autocomplete="off"
                                />
                                <InputError :message="form.errors.api_key" />
                            </div>

                            <div
                                v-if="form.provider === 'unzer'"
                                class="flex flex-col gap-2"
                            >
                                <Label for="private_key"
                                    >Unzer private key</Label
                                >
                                <Input
                                    id="private_key"
                                    v-model="form.private_key"
                                    type="password"
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="form.errors.private_key"
                                />
                            </div>

                            <InputError :message="form.errors.brand_id" />
                            <InputError :message="form.errors.provider" />

                            <div class="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                    >Connect</Button
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    @click="connectingBrandId = null"
                                >
                                    Cancel
                                </Button>
                                <span
                                    v-if="form.isDirty"
                                    role="status"
                                    class="text-sm text-warning"
                                >
                                    Unsaved changes
                                </span>
                            </div>
                        </form>
                    </template>

                    <Button
                        v-else
                        class="self-start"
                        size="sm"
                        @click="beginConnect(brand)"
                    >
                        Connect a provider
                    </Button>
                </template>

                <!--
                    ADR-0057 point 4: the repayment window for money that
                    left again through one of the accounts above.
                -->
                <hr class="border-border" />
                <CommerceBrandSettingsCard
                    :brand-id="brand.id"
                    :settings="brand.commerceSettings"
                />
            </CardContent>
        </Card>
    </div>
</template>
