<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * The billing party block of the public registration form.
 *
 * Extracted from `pages/registration/Register.vue` for the reason
 * `RegistrationEntryConditions.vue` was: the page is held under the ~300-line
 * limit CLAUDE_extra sets, and this is a self-contained section — plain
 * name-carrying inputs Inertia's `<Form>` reads straight out of the DOM, so
 * it holds no state of its own and posts exactly what the page posted before.
 *
 * The postal fields appear only for an invoice-settled type: a legally valid
 * German invoice needs the recipient's address, and
 * `SubmitRegistration::guardBillingParty()` enforces the same set per type
 * under lock, because a hidden field is never the guard.
 */
const props = defineProps<{
    /** Invoice settlement legally needs the recipient's postal address. */
    needsBillingAddress: boolean;
    errors: Record<string, string>;
}>();
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-2">
            <Label for="organizationName">{{
                $t('Organization (optional)')
            }}</Label>
            <Input
                id="organizationName"
                name="organizationName"
                autocomplete="organization"
                :placeholder="$t('Company or organization name')"
            />
            <InputError :message="props.errors.organizationName" />
        </div>

        <div class="grid gap-2">
            <Label for="billingRecipientName">{{
                $t('Billing recipient (optional)')
            }}</Label>
            <Input
                id="billingRecipientName"
                name="billingRecipientName"
                autocomplete="name"
                :placeholder="$t('Who should the invoice be addressed to')"
            />
            <InputError :message="props.errors.billingRecipientName" />
        </div>

        <div class="grid gap-2">
            <Label for="billingEmail">{{
                props.needsBillingAddress
                    ? $t('Billing email')
                    : $t('Billing email (optional)')
            }}</Label>
            <Input
                id="billingEmail"
                type="email"
                name="billingEmail"
                autocomplete="email"
                placeholder="billing@example.com"
            />
            <InputError :message="props.errors.billingEmail" />
        </div>

        <template v-if="props.needsBillingAddress">
            <div class="grid gap-2">
                <Label for="billingAddressLine">{{
                    $t('Street and number')
                }}</Label>
                <Input
                    id="billingAddressLine"
                    name="billingAddressLine"
                    autocomplete="street-address"
                />
                <InputError :message="props.errors.billingAddressLine" />
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div class="grid gap-2">
                    <Label for="billingPostalCode">{{
                        $t('Postal code')
                    }}</Label>
                    <Input
                        id="billingPostalCode"
                        name="billingPostalCode"
                        autocomplete="postal-code"
                    />
                    <InputError :message="props.errors.billingPostalCode" />
                </div>
                <div class="col-span-2 grid gap-2">
                    <Label for="billingCity">{{ $t('City') }}</Label>
                    <Input
                        id="billingCity"
                        name="billingCity"
                        autocomplete="address-level2"
                    />
                    <InputError :message="props.errors.billingCity" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="grid gap-2">
                    <Label for="billingCountryCode">{{
                        $t('Country code')
                    }}</Label>
                    <Input
                        id="billingCountryCode"
                        name="billingCountryCode"
                        autocomplete="country"
                        placeholder="DE"
                        maxlength="2"
                    />
                    <InputError :message="props.errors.billingCountryCode" />
                </div>
                <div class="grid gap-2">
                    <Label for="billingVatId">{{
                        $t('VAT ID (optional)')
                    }}</Label>
                    <Input
                        id="billingVatId"
                        name="billingVatId"
                        placeholder="DE123456789"
                    />
                    <InputError :message="props.errors.billingVatId" />
                </div>
            </div>
        </template>
    </div>
</template>
