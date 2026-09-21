<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import RegistrationController from '@/actions/Konventix/Registration/Http/Controllers/RegistrationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import OfferPrice from '../../components/OfferPrice.vue';
import RegistrationBillingFields from '../../components/RegistrationBillingFields.vue';
import RegistrationEntryConditions from '../../components/RegistrationEntryConditions.vue';

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
 *
 * #234: this is the page where the consumer concludes the contract, so the
 * amount beside the button is the end price PAngV (#3) means — rendered by
 * `OfferPrice`, the same component and the same wording the landing page
 * and `/shop` use. It printed a hardcoded "… € net" before, whatever the
 * type declared.
 */
type Props = {
    registrationTypeId: string;
    eventId: string;
    name: string;
    settlement: string;
    confirmationGate: string;
    price: Konventix.Registration.Data.PriceDeclarationData | null;
    endPrice: Konventix.Platform.Data.EndPriceData | null;
    termsDays: number;
    maxQuantity: number;
    entryConditions: Konventix.Registration.Data.PublicEntryConditionData[];
    alternativeRegistrationTypeId: string | null;
    alternativeRegistrationTypeName: string | null;
    /**
     * Compliance's contributions to this page's own `registration.consent`
     * slot (ADR-0066 §6.2, §7.1) — the legal texts this registration is
     * under. Rendered by component name, never by import: the page does not
     * know which module answered, and Registration never learns that a
     * policy exists (ADR-0004).
     */
    consentSlots: Array<{
        module: string;
        component: string;
        props: Record<string, unknown>;
    }>;
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

/**
 * Whether every entry condition this type demands has been declared
 * (ADR-0066 §6.2) — owned by `RegistrationEntryConditions`, which holds the
 * boxes. A type that demands nothing starts satisfied and the form behaves
 * exactly as it did before the feature existed.
 */
const conditionsSatisfied = ref(props.entryConditions.length === 0);

/**
 * The one declaration the legal texts ask for (D-CPL-08), posted as
 * `acceptedLegalTexts` through a hidden field: the contribution's control is
 * a component with a `v-model`, and Inertia's `<Form>` reads the DOM.
 *
 * `required` on a contribution is what decides whether a tick is owed at
 * all; a page under information-only texts, or under none, submits exactly
 * as it did before this feature. `SubmitRegistration::guardLegalTexts()`
 * asks the same question again under lock.
 */
const acceptedLegalTexts = ref(false);
const consentRequired = props.consentSlots.some(
    (slot) => slot.props.required === true,
);
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

                    <RegistrationBillingFields
                        :needs-billing-address="needsBillingAddress"
                        :errors="errors"
                    />

                    <RegistrationEntryConditions
                        v-if="props.entryConditions.length > 0"
                        :conditions="props.entryConditions"
                        :event-id="props.eventId"
                        :alternative-registration-type-id="
                            props.alternativeRegistrationTypeId
                        "
                        :alternative-registration-type-name="
                            props.alternativeRegistrationTypeName
                        "
                        :errors="errors"
                        @update:satisfied="
                            (value: boolean) => (conditionsSatisfied = value)
                        "
                    />

                    <template
                        v-for="slot in props.consentSlots"
                        :key="slot.component"
                    >
                        <component
                            :is="resolveRegisteredComponent(slot.component)"
                            v-if="resolveRegisteredComponent(slot.component)"
                            v-model="acceptedLegalTexts"
                            v-bind="slot.props"
                            :error="errors.acceptedLegalTexts"
                        />
                    </template>

                    <input
                        type="hidden"
                        name="acceptedLegalTexts"
                        :value="acceptedLegalTexts ? '1' : '0'"
                    />

                    <div
                        class="flex items-center gap-4 border-t border-border pt-4"
                    >
                        <Button
                            :disabled="
                                processing ||
                                !conditionsSatisfied ||
                                (consentRequired && !acceptedLegalTexts)
                            "
                            data-test="submit-registration-button"
                        >
                            Submit registration
                        </Button>
                        <p class="text-sm text-text-muted">
                            <template v-if="props.settlement === 'free'"
                                >This registration is free.</template
                            >
                            <template v-else-if="props.price !== null">
                                <OfferPrice
                                    :price="props.price"
                                    :end-price="props.endPrice"
                                />
                                <template v-if="props.settlement === 'invoice'">
                                    — invoiced, payable within
                                    {{ props.termsDays }} days.
                                </template>
                                <template v-else>.</template>
                            </template>
                        </p>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
