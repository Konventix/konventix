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
    checkout_label: '',
    statement_descriptor_suffix: '',
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
            checkout_label: data.checkout_label,
            statement_descriptor_suffix: data.statement_descriptor_suffix,
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
                    :stripe-setup="props.stripeSetup"
                />

                <template v-if="brand.connectableProviders.length > 0">
                    <template v-if="connectingBrandId === brand.id">
                        <form
                            class="flex max-w-md flex-col gap-4"
                            @submit.prevent="submitConnect"
                        >
                            <!--
                                `brand_id` is set by `transform()` and never
                                typed, so its error belongs to the form rather
                                than to a field — announced, not silently
                                attached to nothing.
                            -->
                            <p
                                v-if="form.errors.brand_id"
                                role="alert"
                                class="rounded-md border border-danger p-3 text-sm font-medium text-danger"
                            >
                                {{ form.errors.brand_id }}
                            </p>

                            <div class="flex flex-col gap-2">
                                <Label for="provider">Provider</Label>
                                <Select v-model="form.provider">
                                    <SelectTrigger
                                        id="provider"
                                        :aria-invalid="
                                            form.errors.provider
                                                ? true
                                                : undefined
                                        "
                                        :aria-describedby="
                                            form.errors.provider
                                                ? 'provider-error'
                                                : undefined
                                        "
                                    >
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
                                <InputError
                                    id="provider-error"
                                    :message="form.errors.provider"
                                />
                            </div>

                            <!--
                                ADR-0058 point 3: enabling Stripe for a
                                brand asks for nothing Stripe has to
                                confirm — only the words the payer sees.
                            -->
                            <template v-if="form.provider === 'stripe'">
                                <p class="text-sm text-text-muted">
                                    This brand will settle through the Stripe
                                    account set above — no separate account, no
                                    onboarding. Enable it and the button appears
                                    at checkout as soon as a key is configured.
                                </p>

                                <div class="flex flex-col gap-2">
                                    <Label for="checkout_label"
                                        >Checkout button label (optional)</Label
                                    >
                                    <Input
                                        id="checkout_label"
                                        v-model="form.checkout_label"
                                        type="text"
                                        autocomplete="off"
                                        placeholder="Card, Apple Pay, Google Pay"
                                        :aria-invalid="
                                            form.errors.checkout_label
                                                ? true
                                                : undefined
                                        "
                                        :aria-describedby="
                                            form.errors.checkout_label
                                                ? 'checkout_label-error'
                                                : undefined
                                        "
                                    />
                                    <InputError
                                        id="checkout_label-error"
                                        :message="form.errors.checkout_label"
                                    />
                                </div>

                                <div class="flex flex-col gap-2">
                                    <Label for="statement_descriptor_suffix"
                                        >Statement descriptor suffix
                                        (optional)</Label
                                    >
                                    <Input
                                        id="statement_descriptor_suffix"
                                        v-model="
                                            form.statement_descriptor_suffix
                                        "
                                        type="text"
                                        maxlength="10"
                                        autocomplete="off"
                                        placeholder="SXLAN"
                                        :aria-invalid="
                                            form.errors
                                                .statement_descriptor_suffix
                                                ? true
                                                : undefined
                                        "
                                        :aria-describedby="
                                            form.errors
                                                .statement_descriptor_suffix
                                                ? 'statement_descriptor_suffix-error'
                                                : undefined
                                        "
                                    />
                                    <InputError
                                        id="statement_descriptor_suffix-error"
                                        :message="
                                            form.errors
                                                .statement_descriptor_suffix
                                        "
                                    />
                                    <p class="text-xs text-text-muted">
                                        What the payer reads on their card
                                        statement, so it names this brand rather
                                        than the operator. Stripe prepends the
                                        account's own prefix and caps the two
                                        together at 22 characters, refusing the
                                        payment if they overflow — so ten
                                        characters is what fits beside the
                                        longest prefix Stripe allows. Shorten
                                        the prefix in the Stripe dashboard if
                                        you need more room here.
                                    </p>
                                </div>
                            </template>

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
                                    :aria-invalid="
                                        form.errors.api_key ? true : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.api_key
                                            ? 'api_key-error'
                                            : undefined
                                    "
                                />
                                <InputError
                                    id="api_key-error"
                                    :message="form.errors.api_key"
                                />
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
                                    :aria-invalid="
                                        form.errors.private_key
                                            ? true
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.private_key
                                            ? 'private_key-error'
                                            : undefined
                                    "
                                />
                                <InputError
                                    id="private_key-error"
                                    :message="form.errors.private_key"
                                />
                            </div>

                            <div class="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                    >{{
                                        form.provider === 'stripe'
                                            ? 'Enable Stripe'
                                            : 'Connect'
                                    }}</Button
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
