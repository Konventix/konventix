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
 * it renders.
 */
type Props = {
    registrationTypeId: string;
    eventId: string;
    name: string;
    settlement: string;
    confirmationGate: string;
    price: {
        unitAmountNet: string;
        taxClass: string;
        display: string;
    } | null;
    termsDays: number;
};

const props = defineProps<Props>();

/** Invoice settlement legally needs the recipient's postal address. */
const needsBillingAddress = props.settlement === 'invoice';
</script>

<template>
    <Head :title="`Register — ${props.name}`" />

    <div class="mx-auto flex min-h-svh max-w-lg flex-col justify-center gap-6 px-4 py-12">
        <Card class="border-border bg-surface">
            <CardContent class="space-y-6 pt-6">
                <Heading :title="props.name" description="Complete the form below to submit your registration." />

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
                    <div class="grid gap-2">
                        <Label for="organizationName">Organization (optional)</Label>
                        <Input
                            id="organizationName"
                            name="organizationName"
                            autocomplete="organization"
                            placeholder="Company or organization name"
                        />
                        <InputError :message="errors.organizationName" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="billingRecipientName">Billing recipient (optional)</Label>
                        <Input
                            id="billingRecipientName"
                            name="billingRecipientName"
                            autocomplete="name"
                            placeholder="Who should the invoice be addressed to"
                        />
                        <InputError :message="errors.billingRecipientName" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="billingEmail">Billing email {{ needsBillingAddress ? '' : '(optional)' }}</Label>
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
                            <Label for="billingAddressLine">Street and number</Label>
                            <Input
                                id="billingAddressLine"
                                name="billingAddressLine"
                                autocomplete="street-address"
                            />
                            <InputError :message="errors.billingAddressLine" />
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div class="grid gap-2">
                                <Label for="billingPostalCode">Postal code</Label>
                                <Input
                                    id="billingPostalCode"
                                    name="billingPostalCode"
                                    autocomplete="postal-code"
                                />
                                <InputError :message="errors.billingPostalCode" />
                            </div>
                            <div class="col-span-2 grid gap-2">
                                <Label for="billingCity">City</Label>
                                <Input id="billingCity" name="billingCity" autocomplete="address-level2" />
                                <InputError :message="errors.billingCity" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-2">
                                <Label for="billingCountryCode">Country code</Label>
                                <Input
                                    id="billingCountryCode"
                                    name="billingCountryCode"
                                    autocomplete="country"
                                    placeholder="DE"
                                    maxlength="2"
                                />
                                <InputError :message="errors.billingCountryCode" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="billingVatId">VAT ID (optional)</Label>
                                <Input id="billingVatId" name="billingVatId" placeholder="DE123456789" />
                                <InputError :message="errors.billingVatId" />
                            </div>
                        </div>
                    </template>

                    <div class="flex items-center gap-4 border-t border-border pt-4">
                        <Button :disabled="processing" data-test="submit-registration-button"> Submit registration </Button>
                        <p class="text-sm text-text-muted">
                            <template v-if="props.settlement === 'free'">This registration is free.</template>
                            <template v-else-if="props.price !== null && props.settlement === 'invoice'">
                                {{ props.price.unitAmountNet }} € net — invoiced, payable within
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
