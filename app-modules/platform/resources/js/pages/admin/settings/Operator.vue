<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update } from '@/routes/admin/settings/operator';

/**
 * The operator running this instance (ADR-0001), and the legal identity every
 * invoice is issued under (ADR-0030).
 *
 * There is no operator picker and no id in the URL: the admin host resolves
 * exactly one operator, and that is the one this page edits.
 *
 * The host itself is not editable here. Changing the address the console
 * answers on, from a page served on that address, is how somebody locks
 * themselves out — it belongs with provisioning.
 */
const props = defineProps<{
    operator: Konventix.Platform.Data.OperatorSettingsData;
}>();

// Nullable columns arrive as null and bind to text inputs as empty strings —
// an <input> has no concept of null. `UpdateOperatorSettings` turns them back
// into nulls on the way in, so a cleared field ends up null in the database
// rather than an empty string that reads as "set to nothing".
const form = useForm({
    name: props.operator.name,
    legalName: props.operator.legalName,
    contactEmail: props.operator.contactEmail ?? '',
    addressLine: props.operator.addressLine ?? '',
    postalCode: props.operator.postalCode ?? '',
    city: props.operator.city ?? '',
    countryCode: props.operator.countryCode,
    vatId: props.operator.vatId ?? '',
    taxNumber: props.operator.taxNumber ?? '',
    smallBusinessScheme: props.operator.smallBusinessScheme,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Operator settings" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Operator settings"
            description="The legal entity running this instance. Its identity appears on every invoice."
        />

        <form class="grid max-w-3xl gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Identity</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">Display name</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="legalName">Legal name</Label>
                        <Input
                            id="legalName"
                            v-model="form.legalName"
                            required
                        />
                        <InputError :message="form.errors.legalName" />
                        <p class="text-xs text-text-muted">
                            Printed as the seller on every invoice. Settlement
                            is refused for a brand whose operator has none.
                        </p>
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="contactEmail">Contact address</Label>
                        <Input
                            id="contactEmail"
                            v-model="form.contactEmail"
                            type="email"
                        />
                        <InputError :message="form.errors.contactEmail" />
                        <p class="text-xs text-text-muted">
                            Where operator-facing notices go — a statutory
                            deadline needs one owner, not a distribution list.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Registered address</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="addressLine">Street and number</Label>
                        <Input id="addressLine" v-model="form.addressLine" />
                        <InputError :message="form.errors.addressLine" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="postalCode">Postal code</Label>
                        <Input id="postalCode" v-model="form.postalCode" />
                        <InputError :message="form.errors.postalCode" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="city">City</Label>
                        <Input id="city" v-model="form.city" />
                        <InputError :message="form.errors.city" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="countryCode">Country code</Label>
                        <Input
                            id="countryCode"
                            v-model="form.countryCode"
                            maxlength="2"
                            required
                        />
                        <InputError :message="form.errors.countryCode" />
                        <p class="text-xs text-text-muted">
                            Two letters, ISO 3166-1 — what ZUGFeRD expects.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Tax</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="vatId">VAT identification number</Label>
                        <Input id="vatId" v-model="form.vatId" />
                        <InputError :message="form.errors.vatId" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="taxNumber">Tax number</Label>
                        <Input id="taxNumber" v-model="form.taxNumber" />
                        <InputError :message="form.errors.taxNumber" />
                    </div>

                    <div class="flex items-start gap-3 sm:col-span-2">
                        <Checkbox
                            id="smallBusinessScheme"
                            v-model="form.smallBusinessScheme"
                        />
                        <div class="grid gap-1">
                            <Label for="smallBusinessScheme">
                                Small-business scheme (§19 UStG)
                            </Label>
                            <p class="text-xs text-text-muted">
                                No VAT is shown on invoices, and each carries
                                the §19 note instead. This is an operator-level
                                election in law, so it applies to every brand.
                            </p>
                        </div>
                        <InputError
                            :message="form.errors.smallBusinessScheme"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
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
    </div>
</template>
