<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock3,
    CreditCard,
    RotateCcw,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { start as startCheckout } from '@/routes/commerce/pay';
import {
    ORDER_WITH_OBLIGATION_TO_PAY,
    fulfilmentNote,
    taxNote,
} from '../../lib/purchaseTexts';

/**
 * Public checkout for an `instant`-settled registration (ADR-0039). This
 * page never decides payment truth — it displays whatever state the
 * webhook has written, and a pending state after returning from the
 * provider simply means the notification has not landed yet.
 *
 * The consent control before the pay button is not this page's to write
 * (GitLab #2): Compliance contributes it to the `checkout.consent` slot and
 * this page renders whatever comes back, the same way `cart/Show.vue` does.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type Props = {
    registrationId: string;
    order: {
        id: string;
        status: string;
        currency: string;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        lines: { label: string; quantity: number; lineGross: string }[];
    };
    accounts: Konventix.Commerce.Data.CheckoutAccountData[];
    paymentStatus: string | null;
    /**
     * ADR-0057 point 4 — the day a reversed order's money is owed back by,
     * from the newest final reversal. Null for every other status.
     */
    repayDueOn: string | null;
    consentSlots: SlotContribution[];
};

const props = defineProps<Props>();

// A refused account (unknown, not offered, or another brand's) comes back
// as a redirect-with-errors, same as any other rejected form field —
// Inertia shares it on `errors.account` for the page that follows.
const page = usePage();
const accountError = computed(() => page.props.errors.account);
const consentError = computed(() => page.props.errors.accept_terms);

const acceptTerms = ref(false);
const consentRequired = computed(() =>
    props.consentSlots.some((slot) => slot.props.required === true),
);
const canPay = computed(() => !consentRequired.value || acceptTerms.value);

// The id of the account currently starting a checkout — only that
// account's button disables, so a second attempt on a different account
// stays reachable.
const starting = ref<string | null>(null);

function pay(accountId: string): void {
    starting.value = accountId;
    router.post(
        startCheckout(props.registrationId).url,
        { account: accountId, accept_terms: acceptTerms.value },
        { onFinish: () => (starting.value = null) },
    );
}

const paid = computed(() => props.order.status === 'paid');

/**
 * ADR-0057 point 3: the sale stands and the amount is owed again. Its own
 * notice rather than a variant of "unpaid" — the payer already paid once,
 * and the page must say why it is asking a second time.
 */
const reversed = computed(() => props.order.status === 'payment_reversed');
const awaitingConfirmation = computed(
    () => !paid.value && props.paymentStatus === 'pending',
);
const lastAttemptFailed = computed(
    () =>
        !paid.value &&
        (props.paymentStatus === 'failed' || props.paymentStatus === 'expired'),
);
</script>

<template>
    <Head title="Payment" />

    <div
        class="mx-auto flex w-full max-w-xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <h1 class="text-2xl font-semibold">Payment</h1>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>Order summary</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(line, index) in props.order.lines"
                    :key="index"
                    class="flex items-center justify-between"
                >
                    <span>{{ line.quantity }}× {{ line.label }}</span>
                    <span>{{ line.lineGross }} {{ props.order.currency }}</span>
                </div>
                <div
                    class="mt-2 flex items-center justify-between border-t border-border pt-2 font-semibold"
                >
                    <span>Total</span>
                    <span
                        >{{ props.order.totalGross }} {{ props.order.currency }}
                        {{ taxNote('gross') }}</span
                    >
                </div>
                <p class="text-xs text-text-muted">
                    includes {{ props.order.totalTax }}
                    {{ props.order.currency }} tax
                </p>
            </CardContent>
        </Card>

        <div
            v-if="reversed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <RotateCcw class="size-5" />
            <span>
                Payment reversed — owed again<template v-if="props.repayDueOn">
                    until {{ props.repayDueOn }}</template
                >.
            </span>
        </div>

        <div
            v-if="paid"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-success"
        >
            <CheckCircle2 class="size-5" />
            <span>Payment received — you are all set.</span>
        </div>

        <div
            v-else-if="awaitingConfirmation"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-text-muted"
        >
            <Clock3 class="size-5" />
            <span>
                Waiting for the payment confirmation from the provider. This
                page can safely be revisited later.
            </span>
        </div>

        <template v-if="!paid">
            <div
                v-if="lastAttemptFailed"
                role="status"
                class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
            >
                <XCircle class="size-5" />
                <span>The last payment attempt did not go through.</span>
            </div>

            <!--
                #75: how and when what was bought arrives, stated before the
                order is concluded rather than only in the confirmation mail.
            -->
            <p class="text-sm text-text-muted">
                {{ fulfilmentNote(props.accounts.length > 0) }}
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

            <div
                v-if="props.accounts.length > 0"
                class="flex flex-col items-start gap-2"
            >
                <Button
                    v-for="account in props.accounts"
                    :key="account.id"
                    :disabled="starting === account.id || !canPay"
                    @click="pay(account.id)"
                >
                    <CreditCard class="size-4" />
                    {{
                        lastAttemptFailed || awaitingConfirmation
                            ? 'Try again'
                            : ORDER_WITH_OBLIGATION_TO_PAY
                    }}
                    — {{ account.label }}
                </Button>

                <p v-if="!canPay" role="status" class="text-sm text-text-muted">
                    Confirm the documents above to pay.
                </p>

                <InputError :message="accountError" />
            </div>
        </template>
    </div>
</template>
