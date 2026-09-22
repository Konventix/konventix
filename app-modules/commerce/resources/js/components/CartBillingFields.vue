<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * The billing-address block of the cart page's step one (#4).
 *
 * §14 Abs. 4 UStG needs the recipient's postal address on the invoice above
 * §33 UStDV's small-amount ceiling, so the cart asks for it before the order
 * is placed rather than after checkout refuses it. The authoritative check
 * stays server-side in `PlaceOrderFromCart::guardBillingAddress()` — the
 * total can still move between this render and the submit.
 *
 * Extracted from `pages/cart/Show.vue` when #219's undo banner arrived, for
 * the reason `RegistrationBillingFields.vue` was extracted from the register
 * form: the page is held under the ~300-line limit CLAUDE_extra sets
 * (`.ai/rules/pages.md`) and this is a self-contained block. Unlike that one
 * it is `v-model`-bound rather than name-carrying, because the cart submits
 * through `router.post()` and not an Inertia `<Form>` — the four values live
 * on the page, which is what sends them.
 *
 * Each error is linked to its field with `aria-invalid` +
 * `aria-describedby` (ADR-0055, `.ai/rules/js.md`): this is the public
 * purchase flow, where an unlinked error is the thing the rule exists for.
 */
const addressLine = defineModel<string>('addressLine', { required: true });
const postalCode = defineModel<string>('postalCode', { required: true });
const city = defineModel<string>('city', { required: true });
const countryCode = defineModel<string>('countryCode', { required: true });

defineProps<{
    errors: Record<string, string>;
}>();
</script>

<template>
    <div class="flex flex-col gap-4 border-t border-border pt-4">
        <p class="text-sm text-text-muted">
            Orders over 250 € need a billing address on the invoice.
        </p>
        <div class="grid gap-2">
            <Label for="billingAddressLine">Street and number</Label>
            <Input
                id="billingAddressLine"
                v-model="addressLine"
                autocomplete="street-address"
                :aria-invalid="errors.billingAddressLine ? true : undefined"
                :aria-describedby="
                    errors.billingAddressLine
                        ? 'billingAddressLine-error'
                        : undefined
                "
            />
            <InputError
                id="billingAddressLine-error"
                :message="errors.billingAddressLine"
            />
        </div>
        <div class="grid grid-cols-3 gap-2">
            <div class="grid gap-2">
                <Label for="billingPostalCode">Postal code</Label>
                <Input
                    id="billingPostalCode"
                    v-model="postalCode"
                    autocomplete="postal-code"
                    :aria-invalid="errors.billingPostalCode ? true : undefined"
                    :aria-describedby="
                        errors.billingPostalCode
                            ? 'billingPostalCode-error'
                            : undefined
                    "
                />
                <InputError
                    id="billingPostalCode-error"
                    :message="errors.billingPostalCode"
                />
            </div>
            <div class="col-span-2 grid gap-2">
                <Label for="billingCity">City</Label>
                <Input
                    id="billingCity"
                    v-model="city"
                    autocomplete="address-level2"
                    :aria-invalid="errors.billingCity ? true : undefined"
                    :aria-describedby="
                        errors.billingCity ? 'billingCity-error' : undefined
                    "
                />
                <InputError
                    id="billingCity-error"
                    :message="errors.billingCity"
                />
            </div>
        </div>
        <div class="grid gap-2">
            <Label for="billingCountryCode">Country code</Label>
            <Input
                id="billingCountryCode"
                v-model="countryCode"
                autocomplete="country"
                maxlength="2"
                class="max-w-24 uppercase"
                :aria-invalid="errors.billingCountryCode ? true : undefined"
                :aria-describedby="
                    errors.billingCountryCode
                        ? 'billingCountryCode-error'
                        : undefined
                "
            />
            <InputError
                id="billingCountryCode-error"
                :message="errors.billingCountryCode"
            />
        </div>
    </div>
</template>
