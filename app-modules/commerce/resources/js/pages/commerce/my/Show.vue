<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Clock3,
    FileText,
    RotateCcw,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { taxNote } from '@/lib/purchaseTexts';
import { index as myOrders } from '@/routes/commerce/mine';
import { show as showRegistration } from '@/routes/registration/mine';
import { orderStatusLabel } from '../../../lib/labels';

/**
 * One order on its own page — where every hosted checkout lands afterwards,
 * whichever way it ended (bugfix round 2026-09-21, Task 3). Every adapter
 * takes one return URL for success, cancel and failure alike
 * (`ProvidesHostedCheckout`), so this page branches on the order's state
 * and never on the way it was reached.
 *
 * It never decides payment truth either: it displays what the webhook has
 * written. Back from the provider before the notification landed, the
 * order is still pending with a pending attempt, and the page says so —
 * the same sentence `pay/Show.vue` uses — rather than guessing. A payer who
 * cancelled at the provider sees the same state plus the "Pay now" button
 * the list already offers, which is what keeps the cancel path working.
 *
 * Task 17b of the same round: the heading links the registration an order
 * settles (as the list does), and every line links to its registration or
 * its shop product page when the server resolved a URL for it.
 */
type Order = Konventix.Commerce.Data.MyOrderDetailData;

const props = defineProps<{ order: Order }>();

const statusTone: Record<string, string> = {
    paid: 'bg-success/15 text-success',
    pending: 'bg-warning/15 text-warning-contrast',
    cancelled: 'bg-danger/15 text-danger',
    refunded: 'bg-subtle text-text',
    payment_reversed: 'bg-danger/15 text-danger',
};

const paid = computed(() => props.order.status === 'paid');
const reversed = computed(() => props.order.status === 'payment_reversed');
const awaitingConfirmation = computed(
    () =>
        props.order.status === 'pending' &&
        props.order.paymentStatus === 'pending',
);
const lastAttemptFailed = computed(
    () =>
        props.order.status === 'pending' &&
        (props.order.paymentStatus === 'failed' ||
            props.order.paymentStatus === 'expired'),
);

const billingLines = computed(() => {
    const party = props.order.billingParty;

    if (party === null) {
        return [];
    }

    return [
        party.organizationName,
        party.recipientName,
        party.addressLine,
        [party.postalCode, party.city].filter(Boolean).join(' '),
        party.countryCode,
    ].filter((line): line is string => Boolean(line));
});
</script>

<template>
    <Head :title="$t('Order')" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6">
        <Link
            :href="myOrders().url"
            class="inline-flex items-center gap-1.5 text-sm text-text-muted underline-offset-4 hover:underline"
        >
            <ArrowLeft class="size-4" />
            {{ $t('My orders') }}
        </Link>

        <header class="mb-2 space-y-0.5">
            <h1 class="text-xl font-semibold tracking-tight">
                <Link
                    v-if="props.order.settlesType === 'registration'"
                    :href="showRegistration(props.order.settlesId).url"
                    class="underline-offset-4 hover:underline"
                >
                    {{ props.order.settlesLabel }}
                </Link>
                <template v-else>{{ props.order.settlesLabel }}</template>
            </h1>
            <p class="text-sm text-text-muted">
                {{
                    $t('Placed :time', {
                        time: formatDateTime(props.order.placedAt),
                    })
                }}
            </p>
        </header>

        <div
            v-if="paid"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-success"
        >
            <CheckCircle2 class="size-5" />
            <span v-if="props.order.paidAt">
                {{
                    $t('Payment received — you are all set (:time).', {
                        time: formatDateTime(props.order.paidAt),
                    })
                }}
            </span>
            <span v-else>{{ $t('Payment received — you are all set.') }}</span>
        </div>

        <div
            v-else-if="reversed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <RotateCcw class="size-5" />
            <span v-if="props.order.repayDueOn">
                {{ $t('Payment reversed — this amount is owed again until') }}
                <time :datetime="props.order.repayDueOn">{{
                    formatCalendarDate(props.order.repayDueOn)
                }}</time
                >.
            </span>
            <span v-else>
                {{ $t('Payment reversed — this amount is owed again.') }}
            </span>
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

        <div
            v-else-if="lastAttemptFailed"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
        >
            <XCircle class="size-5" />
            <span>{{
                $t('The last payment attempt did not go through.')
            }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span
                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                :class="statusTone[props.order.status] ?? 'bg-subtle text-text'"
            >
                {{ orderStatusLabel(props.order.status) }}
            </span>
            <span
                v-if="props.order.invoiceNumber"
                class="text-sm text-text-muted"
            >
                {{
                    $t('Invoice :number', { number: props.order.invoiceNumber })
                }}
            </span>
        </div>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>{{ $t('Order summary') }}</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(line, index) in props.order.lines"
                    :key="index"
                    class="flex items-center justify-between gap-2"
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
                        <span v-else>{{ line.label }}</span>
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
                        $t(':net :currency net, includes :tax :currency tax', {
                            net: props.order.totalNet,
                            tax: props.order.totalTax,
                            currency: props.order.currency,
                        })
                    }}
                </p>
            </CardContent>
        </Card>

        <Card v-if="billingLines.length > 0" class="border-border bg-surface">
            <CardHeader>
                <CardTitle>{{ $t('Billing address') }}</CardTitle>
            </CardHeader>
            <CardContent class="text-sm">
                <p v-for="(line, index) in billingLines" :key="index">
                    {{ line }}
                </p>
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-2">
            <Button v-if="props.order.payUrl" as-child>
                <a :href="props.order.payUrl">{{ $t('Pay now') }}</a>
            </Button>
            <Button v-if="props.order.documentUrl" as-child variant="secondary">
                <a :href="props.order.documentUrl">
                    <FileText class="size-4" />
                    {{
                        props.order.invoicePaid
                            ? $t('Invoice (paid)')
                            : $t('Invoice')
                    }}
                </a>
            </Button>
            <!--
                A refund of a paid order leaves a credit note beside the
                invoice it corrects (ADR-0048 point 2) — one secondary button
                per credit note, linking to the document only once it has
                been generated, exactly as the list does.
            -->
            <template
                v-for="creditNote in props.order.creditNotes"
                :key="creditNote.number"
            >
                <Button
                    v-if="creditNote.documentUrl"
                    as-child
                    variant="secondary"
                >
                    <a :href="creditNote.documentUrl">
                        <FileText class="size-4" />
                        {{
                            $t('Credit note :number', {
                                number: creditNote.number,
                            })
                        }}
                    </a>
                </Button>
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 text-xs text-text-muted"
                >
                    <FileText class="size-4" />
                    {{
                        $t('Credit note :number', {
                            number: creditNote.number,
                        })
                    }}
                </span>
            </template>
        </div>
    </div>
</template>
