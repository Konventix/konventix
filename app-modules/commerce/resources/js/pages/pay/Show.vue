<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { fulfilmentNote, taxNote } from '@/lib/purchaseTexts';
import { choose as chooseAccount } from '@/routes/commerce/pay';

/**
 * Public checkout for an `instant`-settled registration (ADR-0039). This
 * page never decides payment truth — it displays whatever state the
 * webhook has written, and a pending state after returning from the
 * provider simply means the notification has not landed yet.
 *
 * Since ADR-0066 this is **step one of two** (D-CPL-07): it names the order
 * and offers the settlement accounts, and nothing legal at all. Which texts
 * apply may depend on the provider, so the provider is chosen here and the
 * texts are listed on `pay/Confirm.vue`, together with the one accept
 * control. A brand with a single account never sees this page — the server
 * redirects straight to the confirmation with that provider resolved.
 */
type Props = {
    registrationId: string;
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
    accounts: Konventix.Commerce.Data.CheckoutAccountData[];
    paymentStatus: string | null;
    /**
     * #131 — the moment this order's payment window closes, ISO-8601.
     * Sixty minutes from placement on a brand that offers a hosted
     * checkout; null on one that settles by bank transfer, where a transfer
     * takes as long as it takes and nothing sweeps the order.
     */
    holdUntil: string | null;
    payable: boolean;
    /** Why not, in the payer's own words — null while `payable` is true. */
    refusalReason: string | null;
    /**
     * ADR-0057 point 4 — the day a reversed order's money is owed back by,
     * from the newest final reversal. Null for every other status.
     */
    repayDueOn: string | null;
};

const props = defineProps<Props>();

// A refused account (unknown, not offered, or another brand's) comes back
// as a redirect-with-errors, same as any other rejected form field —
// Inertia shares it on `errors.account` for the page that follows.
const page = usePage();
const accountError = computed(() => page.props.errors.account);

/**
 * A visible, explained time limit rather than a ticking counter
 * (.ai/rules/js.md, ADR-0055 point 4): the window is never extended, so the
 * page states the deadline once and says what happens at it.
 */
const holdUntilLabel = computed(() =>
    props.holdUntil === null ? null : formatDateTime(props.holdUntil),
);

// The id of the account currently starting a checkout — only that
// account's button disables, so a second attempt on a different account
// stays reachable.
const starting = ref<string | null>(null);

function choose(accountId: string): void {
    starting.value = accountId;
    router.post(
        chooseAccount(props.registrationId).url,
        { account: accountId },
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
    <Head :title="$t('Payment')" />

    <div
        class="mx-auto flex w-full max-w-xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <h1 class="text-2xl font-semibold">{{ $t('Payment') }}</h1>

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
            </CardContent>
        </Card>

        <div
            v-if="reversed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <RotateCcw class="size-5" />
            <span v-if="props.repayDueOn">
                {{ $t('Payment reversed — owed again until') }}
                <time :datetime="props.repayDueOn">{{
                    formatCalendarDate(props.repayDueOn)
                }}</time
                >.
            </span>
            <span v-else>{{ $t('Payment reversed — owed again.') }}</span>
        </div>

        <div
            v-if="paid"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-success"
        >
            <CheckCircle2 class="size-5" />
            <span>{{ $t('Payment received — you are all set.') }}</span>
        </div>

        <div
            v-else-if="awaitingConfirmation"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-text-muted"
        >
            <Clock3 class="size-5" />
            <span>
                {{
                    $t(
                        'Waiting for the payment confirmation from the provider. This page can safely be revisited later.',
                    )
                }}
            </span>
        </div>

        <template v-if="!paid">
            <div
                v-if="lastAttemptFailed"
                role="status"
                class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
            >
                <XCircle class="size-5" />
                <span>{{
                    $t('The last payment attempt did not go through.')
                }}</span>
            </div>

            <p
                v-if="props.payable && holdUntilLabel"
                role="status"
                class="text-sm text-text-muted"
            >
                {{
                    $t(
                        'Your place is held until :time. After that the order is released and the place goes back on sale.',
                        { time: holdUntilLabel },
                    )
                }}
            </p>

            <p
                v-if="!props.payable"
                role="status"
                class="rounded-md border border-border bg-surface p-4 text-sm text-danger"
            >
                {{ props.refusalReason }}
            </p>

            <template v-if="props.payable">
                <!--
                    #75: how and when what was bought arrives, stated before
                    the order is concluded rather than only in the
                    confirmation mail.
                -->
                <p class="text-sm text-text-muted">
                    {{ fulfilmentNote(props.accounts.length > 0) }}
                </p>

                <div
                    v-if="props.accounts.length > 0"
                    role="group"
                    :aria-label="$t('Payment options')"
                    :aria-describedby="
                        accountError ? 'pay-show-account-error' : undefined
                    "
                    class="flex flex-col items-start gap-2"
                >
                    <Button
                        v-for="account in props.accounts"
                        :key="account.id"
                        :disabled="starting === account.id || !props.payable"
                        @click="choose(account.id)"
                    >
                        <CreditCard class="size-4" />
                        {{
                            lastAttemptFailed || awaitingConfirmation
                                ? $t('Try again — :account', {
                                      account: account.label,
                                  })
                                : $t('Continue — :account', {
                                      account: account.label,
                                  })
                        }}
                    </Button>

                    <p role="status" class="text-sm text-text-muted">
                        {{
                            $t(
                                'You will see what you are agreeing to before the order is placed.',
                            )
                        }}
                    </p>

                    <!--
                        The refusal is linked to the control it is about
                        (.ai/rules/js.md): `InputError` carries no `id` prop
                        of its own yet, so the id sits on the wrapper the
                        button group points at (review 6 parked nit D).
                    -->
                    <div id="pay-show-account-error">
                        <InputError :message="accountError" />
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>
