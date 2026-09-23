<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CreditCard } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { orderWithObligationToPay, taxNote } from '@/lib/purchaseTexts';
import { consentListSignature } from './confirmation';
import type { ConfirmationSlot } from './confirmation';

/**
 * Step two of the checkout (ADR-0066 §6.3, D-CPL-07): what is being bought,
 * how it will be paid, the legal texts that apply to *this* purchase through
 * *that* provider, and the one control that accepts them.
 *
 * Two steps, because which texts apply may depend on the provider — "our
 * Stripe addendum, but only at the big event" is expressible — so the
 * provider is chosen first and the list is resolved after. *Back* returns to
 * step one, and choosing again re-resolves the list: a payer may not be held
 * to have agreed to a list they never saw. The acceptance is cleared
 * whenever the list underneath it changes, here in the browser as well as on
 * the server, which is the property `confirmation.ts` tests.
 *
 * Serves both addressed pairs — the registration checkout and the
 * order-addressed repay page — because past the choice they are the same
 * act; `startUrl` and `backUrl` are what differs. `backUrl` is null for a
 * brand with a single settlement account: there is no choice to go back to,
 * and a link there would bounce straight back here.
 *
 * The consent control is not this page's to write (GitLab #2): Compliance
 * contributes it to the `checkout.consent` slot and this page renders
 * whatever comes back, never learning that a policy exists.
 */
const props = defineProps<{
    order: {
        id: string;
        status: string;
        currency: string;
        /**
         * The §19 UStG election as settlement resolved it for this order
         * (#244): no VAT is charged at all then, and the total's label says
         * so rather than claiming one.
         */
        smallBusinessScheme: boolean;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        lines: Konventix.Commerce.Data.MyOrderLineData[];
    };
    account: Konventix.Commerce.Data.CheckoutAccountData;
    consentSlots: ConfirmationSlot[];
    startUrl: string;
    backUrl: string | null;
    singleAccount: boolean;
    /**
     * #131 — when this order's payment window closes, ISO-8601, or null on a
     * brand that settles by bank transfer. Stated once and never ticking
     * (.ai/rules/js.md, ADR-0055 point 4); it is on this page as well as on
     * step one because a single-account brand never sees step one.
     */
    holdUntil: string | null;
    paymentStatus: string | null;
    /** ADR-0057 point 4 — the day a reversed order's money is owed back by. */
    repayDueOn: string | null;
}>();

const page = usePage();
const accountError = computed(() => page.props.errors.account);
const consentError = computed(() => page.props.errors.accept_terms);

const acceptTerms = ref(false);
const starting = ref(false);

const holdUntilLabel = computed(() =>
    props.holdUntil === null ? null : formatDateTime(props.holdUntil),
);
const lastAttemptFailed = computed(
    () => props.paymentStatus === 'failed' || props.paymentStatus === 'expired',
);

const consentRequired = computed(() =>
    props.consentSlots.some((slot) => slot.props.required === true),
);
const canPay = computed(() => !consentRequired.value || acceptTerms.value);

// A partial reload that brings back a different list — a republished
// version, a provider changed in another tab — must not carry an acceptance
// made against the old one (D-CPL-07).
watch(
    () => consentListSignature(props.consentSlots),
    () => {
        acceptTerms.value = false;
    },
);

function pay(): void {
    starting.value = true;
    router.post(
        props.startUrl,
        { account: props.account.id, accept_terms: acceptTerms.value },
        { onFinish: () => (starting.value = false) },
    );
}
</script>

<template>
    <Head :title="$t('Confirm your order')" />

    <div
        class="mx-auto flex w-full max-w-xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <h1 class="text-2xl font-semibold">{{ $t('Confirm your order') }}</h1>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>{{ $t('Order summary') }}</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(line, index) in props.order.lines"
                    :key="index"
                    class="flex items-center justify-between"
                >
                    <span>
                        {{ line.quantity }}×
                        <Link
                            v-if="line.registrationUrl ?? line.productUrl"
                            :href="
                                line.registrationUrl ?? line.productUrl ?? ''
                            "
                            class="underline-offset-4 hover:underline"
                        >
                            {{ line.label }}
                        </Link>
                        <template v-else>{{ line.label }}</template>
                    </span>
                    <span>{{ line.lineGross }} {{ props.order.currency }}</span>
                </div>
                <div
                    class="mt-2 flex items-center justify-between border-t border-border pt-2 font-semibold"
                >
                    <span>{{ $t('Total') }}</span>
                    <span
                        >{{ props.order.totalGross }} {{ props.order.currency }}
                        {{
                            taxNote('gross', props.order.smallBusinessScheme)
                        }}</span
                    >
                </div>
                <p class="text-xs text-text-muted">
                    {{
                        $t('includes :amount :currency tax', {
                            amount: props.order.totalTax,
                            currency: props.order.currency,
                        })
                    }}
                </p>
                <p class="mt-2 border-t border-border pt-2">
                    {{ $t('Paying with') }}
                    <strong>{{ props.account.label }}</strong>
                </p>
            </CardContent>
        </Card>

        <p
            v-if="lastAttemptFailed"
            role="status"
            class="rounded-md border border-border bg-surface p-4 text-sm text-danger"
        >
            {{ $t('The last payment attempt did not go through.') }}
        </p>

        <p
            v-if="props.repayDueOn"
            role="status"
            class="text-sm text-text-muted"
        >
            {{ $t('This payment was reversed and is owed back until') }}
            <time :datetime="props.repayDueOn">{{
                formatCalendarDate(props.repayDueOn)
            }}</time
            >.
        </p>

        <p v-if="holdUntilLabel" role="status" class="text-sm text-text-muted">
            {{
                $t(
                    'Your place is held until :time. After that the order is released and the place goes back on sale.',
                    { time: holdUntilLabel },
                )
            }}
        </p>

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
            <Button :disabled="starting || !canPay" @click="pay()">
                <CreditCard class="size-4" />
                {{ orderWithObligationToPay() }}
            </Button>

            <p v-if="!canPay" role="status" class="text-sm text-text-muted">
                {{ $t('Confirm the documents above to pay.') }}
            </p>

            <div id="pay-confirm-account-error">
                <InputError :message="accountError" />
            </div>

            <Link
                v-if="props.backUrl !== null"
                :href="props.backUrl"
                class="flex items-center gap-1 text-sm underline underline-offset-4"
            >
                <ArrowLeft class="size-4" />
                {{ $t('Choose a different payment option') }}
            </Link>
        </div>
    </div>
</template>
