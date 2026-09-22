<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CreditCard } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { ORDER_WITH_OBLIGATION_TO_PAY, taxNote } from '@/lib/purchaseTexts';
import { consentListSignature } from '../pay/confirmation';
import type { ConfirmationSlot } from '../pay/confirmation';

/**
 * Step two of the cart checkout (ADR-0066 §6.3, D-CPL-07) — the cart's
 * equivalent of `pay/Confirm.vue`, and deliberately its own page rather than
 * a shared one: a cart order names lines the payer assembled and a
 * registration order names one the organizer priced, and the two summaries
 * are not the same thing.
 *
 * What it shares is the rule. The provider was chosen on the cart; the texts
 * listed here are the ones this purchase is under *through that provider*;
 * *Back* returns to the cart and clears both the choice and the acceptance,
 * because a payer may not be held to a list they never saw. The acceptance
 * is cleared in the browser too whenever the list underneath it changes —
 * `pay/confirmation.ts` owns that decision and its tests.
 *
 * No dirty guard (ADR-0038): there is no draft state here, only a tick and a
 * button.
 */
const props = defineProps<{
    summary: {
        currency: string;
        /**
         * The §19 UStG election this step's own calculation resolved
         * (#244) — the page carrying the §312j (3) BGB button may not
         * label a total "incl. VAT" when no VAT is being charged.
         */
        smallBusinessScheme: boolean;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        lines: { label: string; quantity: number; lineGross: string }[];
    };
    account: Konventix.Commerce.Data.CheckoutAccountData;
    consentSlots: ConfirmationSlot[];
    startUrl: string;
    backUrl: string;
}>();

const page = usePage();
const accountError = computed(() => page.props.errors.account);
const consentError = computed(() => page.props.errors.accept_terms);

const acceptTerms = ref(false);
const placing = ref(false);

const consentRequired = computed(() =>
    props.consentSlots.some((slot) => slot.props.required === true),
);
const canCheckout = computed(() => !consentRequired.value || acceptTerms.value);

watch(
    () => consentListSignature(props.consentSlots),
    () => {
        acceptTerms.value = false;
    },
);

function checkout(): void {
    placing.value = true;
    router.post(
        props.startUrl,
        { account: props.account.id, accept_terms: acceptTerms.value },
        { onFinish: () => (placing.value = false) },
    );
}
</script>

<template>
    <Head title="Confirm your order" />

    <div
        class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <Heading as="h1" title="Confirm your order" />

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>Order summary</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(line, index) in props.summary.lines"
                    :key="index"
                    class="flex items-center justify-between"
                >
                    <span>{{ line.quantity }}× {{ line.label }}</span>
                    <span
                        >{{ line.lineGross }} {{ props.summary.currency }}</span
                    >
                </div>
                <div
                    class="mt-2 flex items-center justify-between border-t border-border pt-2 font-semibold"
                >
                    <span>Total</span>
                    <span
                        >{{ props.summary.totalGross }}
                        {{ props.summary.currency }}
                        {{
                            taxNote('gross', props.summary.smallBusinessScheme)
                        }}</span
                    >
                </div>
                <p class="text-xs text-text-muted">
                    includes {{ props.summary.totalTax }}
                    {{ props.summary.currency }} tax
                </p>
                <p class="mt-2 border-t border-border pt-2">
                    Paying with <strong>{{ props.account.label }}</strong>
                </p>
            </CardContent>
        </Card>

        <template v-for="slot in props.consentSlots" :key="slot.component">
            <component
                :is="resolveRegisteredComponent(slot.component)"
                v-if="resolveRegisteredComponent(slot.component)"
                v-model="acceptTerms"
                v-bind="slot.props"
                :error="consentError"
            />
        </template>

        <div class="flex flex-col items-start gap-2">
            <Button :disabled="placing || !canCheckout" @click="checkout()">
                <CreditCard class="size-4" />
                {{ ORDER_WITH_OBLIGATION_TO_PAY }}
            </Button>

            <p
                v-if="!canCheckout"
                role="status"
                class="text-sm text-text-muted"
            >
                Confirm the documents above to place your order.
            </p>

            <InputError :message="accountError" />

            <Link
                :href="props.backUrl"
                class="flex items-center gap-1 text-sm underline underline-offset-4"
            >
                <ArrowLeft class="size-4" />
                Back to your cart
            </Link>
        </div>
    </div>
</template>
