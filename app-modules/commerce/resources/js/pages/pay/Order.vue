<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, CreditCard, RotateCcw, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import {
    ORDER_WITH_OBLIGATION_TO_PAY,
    fulfilmentNote,
    taxNote,
} from '@/lib/purchaseTexts';
import { start as startOrderCheckout } from '@/routes/commerce/pay/order';

/**
 * The way back to an order whose payment failed (GitLab #5) — a cart order
 * (ADR-0047), which has no registration ULID to be addressed by, so this
 * page is addressed by the order itself.
 *
 * Deliberately not the cart page: a payer who kept shopping after an
 * abandoned checkout has a second, open cart, and a page that showed "the
 * newest cart" would show the wrong one and check *it* out. Here the order
 * is named, so what is on screen is what the mail was about.
 *
 * The reservation is the first placement's, never renewed by arriving here
 * (`ExpireCarts` owns when it ends, ADR-0047 point 4). Once it has run out
 * the server says so in `refusalReason` and this page states it instead of
 * offering a button that cannot help — a payer holding a mail deserves the
 * sentence rather than an error page.
 *
 * The consent control before the pay button is not this page's to write
 * (GitLab #2): Compliance contributes it to the `checkout.consent` slot and
 * this page renders whatever comes back, exactly as `pay/Show.vue` does.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type Props = {
    order: {
        id: string;
        status: string;
        currency: string;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        lines: Konventix.Commerce.Data.MyOrderLineData[];
    };
    accounts: Konventix.Commerce.Data.CheckoutAccountData[];
    paymentStatus: string | null;
    /** The first placement's deadline, ISO-8601, or null when there is none. */
    holdUntil: string | null;
    /**
     * ADR-0057 point 4 — the day a reversed order's money is owed back by,
     * from the newest final reversal. Null for every other status.
     */
    repayDueOn: string | null;
    payable: boolean;
    /** Why not, in the payer's own words — null while `payable` is true. */
    refusalReason: string | null;
    consentSlots: SlotContribution[];
};

const props = defineProps<Props>();

const page = usePage();
const accountError = computed(() => page.props.errors.account);
const consentError = computed(() => page.props.errors.accept_terms);

const acceptTerms = ref(false);
const consentRequired = computed(() =>
    props.consentSlots.some((slot) => slot.props.required === true),
);
const canPay = computed(
    () => props.payable && (!consentRequired.value || acceptTerms.value),
);

// Only the account whose button was pressed disables, so a second attempt
// on another account stays reachable — same rule as `pay/Show.vue`.
const starting = ref<string | null>(null);

function pay(accountId: string): void {
    starting.value = accountId;
    router.post(
        startOrderCheckout(props.order.id).url,
        { account: accountId, accept_terms: acceptTerms.value },
        { onFinish: () => (starting.value = null) },
    );
}

/**
 * The paid branch this page lacked (bugfix round 2026-09-21, Task 3): a
 * provider used to send a successful payer back here, and the page greeted
 * them with the red "already settled" refusal. Checkouts return to
 * `/my/orders/{order}` now, but this page is still reachable from a mail
 * or a bookmark, so a paid order reads as a confirmation here too.
 */
const paid = computed(() => props.order.status === 'paid');

const lastAttemptFailed = computed(
    () =>
        !paid.value &&
        (props.paymentStatus === 'failed' || props.paymentStatus === 'expired'),
);

const reversed = computed(() => props.order.status === 'payment_reversed');

/**
 * A visible, explained time limit rather than a ticking counter
 * (.ai/rules/js.md, ADR-0055 point 4): the hold is never extended, so the
 * page states the deadline once and says what happens at it.
 */
const holdUntilLabel = computed(() =>
    props.holdUntil === null ? null : formatDateTime(props.holdUntil),
);
</script>

<template>
    <Head title="Pay for your order" />

    <div
        class="mx-auto flex w-full max-w-xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <h1 class="text-2xl font-semibold">Pay for your order</h1>

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
            v-if="paid"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-success"
        >
            <CheckCircle2 class="size-5" />
            <span>Payment received — you are all set.</span>
        </div>

        <div
            v-else-if="reversed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <RotateCcw class="size-5" />
            <span>
                Payment reversed — this amount is owed again<template
                    v-if="props.repayDueOn"
                >
                    until
                    <time :datetime="props.repayDueOn">{{
                        formatCalendarDate(props.repayDueOn)
                    }}</time></template
                >.
            </span>
        </div>

        <div
            v-else-if="lastAttemptFailed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <XCircle class="size-5" />
            <span>The last payment attempt did not go through.</span>
        </div>

        <!--
            #186: a reversed order is payable long past the reservation the
            first payment used, so the hold is no longer the deadline that
            binds it — the banner above names the one that does.
        -->
        <p
            v-if="props.payable && holdUntilLabel && !reversed"
            role="status"
            class="text-sm text-text-muted"
        >
            Your reservation is held until {{ holdUntilLabel }}. After that the
            order is released and the items go back on sale.
        </p>

        <p
            v-if="!props.payable && !paid"
            role="status"
            class="rounded-md border border-border bg-surface p-4 text-sm text-danger"
        >
            {{ props.refusalReason }}
        </p>

        <template v-if="props.payable">
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
                role="group"
                aria-label="Payment options"
                :aria-describedby="
                    accountError ? 'pay-order-account-error' : undefined
                "
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
                        lastAttemptFailed
                            ? 'Try again'
                            : ORDER_WITH_OBLIGATION_TO_PAY
                    }}
                    — {{ account.label }}
                </Button>

                <p v-if="!canPay" role="status" class="text-sm text-text-muted">
                    Confirm the documents above to pay.
                </p>

                <!--
                    The refusal is linked to the control it is about
                    (.ai/rules/js.md): `InputError` carries no `id` prop of
                    its own yet, so the id sits on the wrapper the button
                    group points at (review 6 parked nit D).
                -->
                <div id="pay-order-account-error">
                    <InputError :message="accountError" />
                </div>
            </div>

            <p v-else class="text-sm text-text-muted">
                This organizer settles by bank transfer — payment details arrive
                with your confirmation.
            </p>
        </template>
    </div>
</template>
