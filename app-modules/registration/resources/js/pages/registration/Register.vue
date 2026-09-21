<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import RegistrationController from '@/actions/Konventix/Registration/Http/Controllers/RegistrationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Props are `Konventix\Registration\Data\RegistrationFormData` (ADR-0009) —
 * the single source for what this page is allowed to know about the offer
 * it renders — spread by the controller beside the invitation token.
 *
 * Written out rather than referenced as
 * `Konventix.Registration.Data.RegistrationFormData`: `defineProps<T>()` is
 * compiled by `@vue/compiler-sfc`, which resolves the *root* props type on
 * its own and cannot see an ambient global namespace — the build fails with
 * "Unresolvable type reference". The generated type is usable one level
 * down (see the type editor's `type:` prop), never as the root.
 */
type Props = {
    registrationTypeId: string;
    eventId: string;
    name: string;
    settlement: string;
    confirmationGate: string;
    price: Konventix.Registration.Data.PriceDeclarationData | null;
    termsDays: number;
    maxQuantity: number;
};

const props = defineProps<Props>();

/** Invoice settlement legally needs the recipient's postal address. */
const needsBillingAddress = props.settlement === 'invoice';

/**
 * The quantity control exists only where there is a choice to make (Task
 * 19): `maxQuantity` is already `min(type cap, remaining quota)` and a
 * ceiling of one means the type never opted into multiple units — the form
 * then sends nothing and the Data object's default of one applies.
 */
const offersQuantity = props.maxQuantity > 1;
</script>

<template>
    <Head :title="`Register — ${props.name}`" />

    <div
        class="mx-auto flex min-h-svh max-w-lg flex-col justify-center gap-6 px-4 py-12"
    >
        <Card class="border-border bg-surface">
            <CardContent class="space-y-6 pt-6">
                <Heading
                    as="h1"
                    :title="props.name"
                    description="Complete the form below to submit your registration."
                />

                <Form
                    v-bind="
                        RegistrationController.store.form({
                            event_ulid: props.eventId,
                            type: props.registrationTypeId,
                        })
                    "
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <!--
                        #218: a refusal that belongs to no single input — the
                        type's sale has not opened, or has closed since this
                        page was loaded. It leads the form and announces
                        itself, because nothing the visitor could retype
                        would fix it (ADR-0055).
                    -->
                    <div
                        v-if="errors.registrationType"
                        role="alert"
                        class="rounded-md border border-danger/30 bg-danger/10 p-3 text-sm text-text"
                    >
                        {{ errors.registrationType }}
                    </div>

                    <div v-if="offersQuantity" class="grid gap-2">
                        <Label for="quantity">How many?</Label>
                        <Input
                            id="quantity"
                            name="quantity"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            :max="props.maxQuantity"
                            :default-value="1"
                            class="max-w-24"
                            :aria-invalid="errors.quantity ? 'true' : undefined"
                            :aria-describedby="
                                errors.quantity
                                    ? 'quantity-error'
                                    : 'quantity-hint'
                            "
                        />
                        <p id="quantity-hint" class="text-xs text-text-muted">
                            Up to {{ props.maxQuantity }} per registration.
                        </p>
                        <InputError
                            id="quantity-error"
                            :message="errors.quantity"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="organizationName"
                            >Organization (optional)</Label
                        >
                        <Input
                            id="organizationName"
                            name="organizationName"
                            autocomplete="organization"
                            placeholder="Company or organization name"
                        />
                        <InputError :message="errors.organizationName" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="billingRecipientName"
                            >Billing recipient (optional)</Label
                        >
                        <Input
                            id="billingRecipientName"
                            name="billingRecipientName"
                            autocomplete="name"
                            placeholder="Who should the invoice be addressed to"
                        />
                        <InputError :message="errors.billingRecipientName" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="billingEmail"
                            >Billing email
                            {{ needsBillingAddress ? '' : '(optional)' }}</Label
                        >
                        <Input
                            id="billingEmail"
                            type="email"
                            name="billingEmail"
                            autocomplete="email"
                            placeholder="billing@example.com"
                        />
                        <InputError :message="errors.billingEmail" />
                    </div>

                    <template v-if="needsBillingAddress">
                        <div class="grid gap-2">
                            <Label for="billingAddressLine"
                                >Street and number</Label
                            >
                            <Input
                                id="billingAddressLine"
                                name="billingAddressLine"
                                autocomplete="street-address"
                            />
                            <InputError :message="errors.billingAddressLine" />
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div class="grid gap-2">
                                <Label for="billingPostalCode"
                                    >Postal code</Label
                                >
                                <Input
                                    id="billingPostalCode"
                                    name="billingPostalCode"
                                    autocomplete="postal-code"
                                />
                                <InputError
                                    :message="errors.billingPostalCode"
                                />
                            </div>
                            <div class="col-span-2 grid gap-2">
                                <Label for="billingCity">City</Label>
                                <Input
                                    id="billingCity"
                                    name="billingCity"
                                    autocomplete="address-level2"
                                />
                                <InputError :message="errors.billingCity" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-2">
                                <Label for="billingCountryCode"
                                    >Country code</Label
                                >
                                <Input
                                    id="billingCountryCode"
                                    name="billingCountryCode"
                                    autocomplete="country"
                                    placeholder="DE"
                                    maxlength="2"
                                />
                                <InputError
                                    :message="errors.billingCountryCode"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="billingVatId"
                                    >VAT ID (optional)</Label
                                >
                                <Input
                                    id="billingVatId"
                                    name="billingVatId"
                                    placeholder="DE123456789"
                                />
                                <InputError :message="errors.billingVatId" />
                            </div>
                        </div>
                    </template>

                    <div
                        class="flex items-center gap-4 border-t border-border pt-4"
                    >
                        <Button
                            :disabled="processing"
                            data-test="submit-registration-button"
                        >
                            Submit registration
                        </Button>
                        <p class="text-sm text-text-muted">
                            <template v-if="props.settlement === 'free'"
                                >This registration is free.</template
                            >
                            <template
                                v-else-if="
                                    props.price !== null &&
                                    props.settlement === 'invoice'
                                "
                            >
                                {{ props.price.unitAmountNet }} € net —
                                invoiced, payable within
                                {{ props.termsDays }} days.
                            </template>
                            <template v-else-if="props.price !== null">
                                {{ props.price.unitAmountNet }} € net.
                            </template>
                        </p>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
