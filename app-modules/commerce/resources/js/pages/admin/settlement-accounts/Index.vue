<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Unplug } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
    disconnect as disconnectAccount,
    mollie as connectMollie,
    stripe as connectStripe,
    unzer as connectUnzer,
} from '@/routes/admin/settlement-accounts';

/**
 * Where each brand's money goes (ADR-0007/ADR-0039). One live account per
 * brand; switching providers is disconnect-then-connect, so the form only
 * appears for brands without one.
 */
type Account = {
    id: string;
    provider: string;
    label: string | null;
    providerAccountId: string | null;
    connectedAt: string | null;
    pendingOnboarding: boolean;
};

type Props = {
    brands: { id: string; name: string; account: Account | null }[];
};

const props = defineProps<Props>();

const connectingBrandId = ref<string | null>(null);

const form = useForm({
    // Overwritten by `transform()` on submit, but declared here so the key
    // exists on the form's type — the server validates `brand_id` and its
    // error had nowhere to land otherwise.
    brand_id: '',
    provider: 'stripe',
    contact_email: '',
    api_key: '',
    private_key: '',
});

useUnsavedChanges(() => form.isDirty);

function beginConnect(brandId: string): void {
    connectingBrandId.value = brandId;
    form.reset();
    form.clearErrors();
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

function disconnect(account: Account): void {
    if (
        confirm(
            'Disconnect this account? The brand falls back to manual booking until a new one is connected.',
        )
    ) {
        form.post(disconnectAccount(account.id).url, { preserveScroll: true });
    }
}

const providerNames: Record<string, string> = {
    manual: 'Manual (bank transfer)',
    stripe: 'Stripe',
    mollie: 'Mollie',
    unzer: 'Unzer',
};
</script>

<template>
    <Head title="Settlement accounts" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Settlement accounts"
            description="Each brand's own payment-provider connection"
        />

        <p class="max-w-2xl text-sm text-text-muted">
            Money flows directly from the participant into the organizer's own
            provider account — the platform never holds funds. A brand without a
            connected account settles manually.
        </p>

        <Card
            v-for="brand in props.brands"
            :key="brand.id"
            class="border-border bg-surface"
        >
            <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle>{{ brand.name }}</CardTitle>
                <Badge v-if="brand.account" variant="secondary">
                    {{
                        providerNames[brand.account.provider] ??
                        brand.account.provider
                    }}
                </Badge>
                <Badge v-else variant="outline">manual</Badge>
            </CardHeader>

            <CardContent class="flex flex-col gap-4">
                <template v-if="brand.account">
                    <div
                        class="flex flex-wrap items-center gap-4 text-sm text-text-muted"
                    >
                        <span v-if="brand.account.label">{{
                            brand.account.label
                        }}</span>
                        <span
                            v-if="brand.account.providerAccountId"
                            class="font-mono"
                        >
                            {{ brand.account.providerAccountId }}
                        </span>
                        <Badge
                            v-if="brand.account.pendingOnboarding"
                            variant="destructive"
                        >
                            onboarding not finished
                        </Badge>
                        <span v-else-if="brand.account.connectedAt">
                            connected {{ brand.account.connectedAt }}
                        </span>
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        class="self-start"
                        @click="disconnect(brand.account)"
                    >
                        <Unplug class="size-4" />
                        Disconnect
                    </Button>
                </template>

                <template v-else-if="connectingBrandId === brand.id">
                    <form
                        class="flex max-w-md flex-col gap-4"
                        @submit.prevent="submitConnect"
                    >
                        <div class="flex flex-col gap-2">
                            <Label for="provider">Provider</Label>
                            <Select v-model="form.provider">
                                <SelectTrigger id="provider">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="stripe"
                                        >Stripe</SelectItem
                                    >
                                    <SelectItem value="mollie"
                                        >Mollie</SelectItem
                                    >
                                    <SelectItem value="unzer">Unzer</SelectItem>
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
                            <InputError :message="form.errors.contact_email" />
                            <p class="text-xs text-text-muted">
                                Connecting opens Stripe's hosted onboarding for
                                the organizer's own account.
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
                            <Label for="private_key">Unzer private key</Label>
                            <Input
                                id="private_key"
                                v-model="form.private_key"
                                type="password"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.private_key" />
                        </div>

                        <InputError :message="form.errors.brand_id" />

                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing"
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
                    @click="beginConnect(brand.id)"
                >
                    Connect a provider
                </Button>
            </CardContent>
        </Card>
    </div>
</template>
