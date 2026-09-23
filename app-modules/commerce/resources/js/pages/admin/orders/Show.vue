<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertCircle, FileText, Landmark, Undo2, XCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { document as creditNoteDocument } from '@/routes/admin/credit-notes';
import { document as invoiceDocument } from '@/routes/admin/invoices';
import {
    billingAddress,
    bookPayment,
    cancelReversed,
} from '@/routes/admin/orders';
import OrderRefundDialog from '../../../components/admin/OrderRefundDialog.vue';
import {
    orderStatusLabel,
    paymentStatusLabel,
    refundReasonLabel,
    reversalStatusLabel,
    settlesTypeLabel,
    taxTreatmentLabel,
} from '../../../lib/labels';
import type { OrderReversal, UnappliedPayment } from './reversals';
import {
    canBookPayment,
    repaymentDeadline,
    reversalKindLabel,
} from './reversals';

/**
 * One order end to end: lines, invoice with billing party, payment
 * attempts (webhooks are the settlement truth — ADR-0039) and credit
 * notes. Booking a bank transfer by hand lives here as well as on the
 * index (separate capability, ADR-0013).
 */
type Props = {
    order: {
        id: string;
        brand: string;
        settlesType: string;
        settlesId: string;
        status: string;
        currency: string;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        taxTreatment: string;
        provider: string | null;
        paidAt: string | null;
        createdAt: string | null;
        /** #4: §14 Abs. 4 UStG applies above GermanVat's small-amount ceiling. */
        billingAddressRequired: boolean;
        billingParty: {
            organizationName: string | null;
            recipientName: string | null;
            email: string | null;
            addressLine: string | null;
            postalCode: string | null;
            city: string | null;
            countryCode: string | null;
            vatId: string | null;
            hasPostalAddress: boolean;
        } | null;
    };
    /**
     * Each line with what is left of it to credit (#7, D-0.8.2-1). The
     * generated `Data` type, never a hand-written shape (ADR-0009) — the
     * refund dialog's picker reads `remainingQuantity` off it.
     */
    lines: Konventix.Commerce.Data.OrderCreditLineData[];
    invoice: {
        id: string;
        number: string;
        issuedOn: string;
        dueOn: string;
        totalGross: string;
        documentReady: boolean;
        billingParty: {
            name: string;
            email: string | null;
            city: string | null;
            countryCode: string | null;
            vatId: string | null;
        };
    } | null;
    payments: {
        id: string;
        provider: string;
        status: string;
        amount: string;
        currency: string;
        externalId: string | null;
        failureReason: string | null;
        receivedAt: string | null;
        failedAt: string | null;
    }[];
    creditNotes: {
        id: string;
        number: string;
        totalGross: string;
        reasonCode: string;
        issuedOn: string;
        documentReady: boolean;
    }[];
    /** ADR-0057 point 8 — every row the order has, withdrawn ones included. */
    reversals: OrderReversal[];
    /** ADR-0057 point 9 / #6 — money the order could not take. */
    unappliedPayment: UnappliedPayment | null;
};

const props = defineProps<Props>();

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'paid') {
        return 'default';
    }

    return status === 'cancelled' || status === 'payment_reversed'
        ? 'destructive'
        : 'secondary';
}

/** The wording and the deadline rule are shared with the index list. */
const deadline = computed<string | null>(() =>
    repaymentDeadline(props.reversals),
);

/**
 * A reversed payment is not "some non-succeeded state" (ADR-0057's
 * consequences say the detail page must show it as what it is): the money
 * arrived and left again, which reads as loss, not as pending.
 */
function paymentVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'succeeded') {
        return 'default';
    }

    return status === 'failed' || status === 'reversed'
        ? 'destructive'
        : 'secondary';
}

function submitBookPayment(): void {
    router.post(bookPayment(props.order.id).url, {}, { preserveScroll: true });
}

/**
 * #4: the retrofit for an order the small-amount threshold caught with no
 * address (`IssueInvoiceOnOrderPaid`'s own docblock) — shown only where it
 * could possibly apply, and never once an invoice already exists, since an
 * issued one is frozen for good (ADR-0031). Pre-filled from whatever the
 * order's own party already carries (name and email survive from the
 * account, ADR-0048), so this reads as completing the party, not starting
 * one from nothing.
 */
const showBillingAddressForm = computed(
    () =>
        props.order.billingAddressRequired &&
        !props.order.billingParty?.hasPostalAddress &&
        props.invoice === null,
);

const billingAddressForm = useForm({
    organizationName: props.order.billingParty?.organizationName ?? null,
    recipientName: props.order.billingParty?.recipientName ?? null,
    addressLine: props.order.billingParty?.addressLine ?? null,
    postalCode: props.order.billingParty?.postalCode ?? null,
    city: props.order.billingParty?.city ?? null,
    countryCode: props.order.billingParty?.countryCode ?? 'DE',
    vatId: props.order.billingParty?.vatId ?? null,
});

useUnsavedChanges(
    () => showBillingAddressForm.value && billingAddressForm.isDirty,
);

function submitBillingAddress(): void {
    billingAddressForm.post(billingAddress(props.order.id).url, {
        preserveScroll: true,
    });
}

/**
 * ADR-0057 point 4: the operator's manual "cancel now" for a reversed order
 * whose payment never came back — the deadline sweep does the same thing
 * automatically, this button lets the operator do it earlier. A credit note
 * corrects the invoice where one exists; the confirmation follows the same
 * plain-`confirm()` pattern the products page uses for its own destructive
 * action (`products/Edit.vue`).
 */
function submitCancelReversed(): void {
    if (
        confirm(
            trans(
                'Cancel this order? Where an invoice exists it will be credited, and the reversed payment will no longer be chased.',
            ),
        )
    ) {
        router.post(
            cancelReversed(props.order.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

/**
 * Two orders can be refunded (#6, ADR-0057 point 9): a paid one, and a
 * cancelled one still holding money nobody could apply — the PSP success
 * that landed after a sweep had already called the order off. The second is
 * `RefundOrder`'s provider leg without a credit note: the money goes back
 * through the account it came from, the order stays cancelled, and no
 * document is written, because a cancelled order has none to correct.
 *
 * The form itself — the reason, the note and D-0.8.2-1's line-and-quantity
 * picker — is `OrderRefundDialog.vue`. It owns its own `useForm`, so
 * nothing of it is left on this page but the button that opens it.
 */
const canRefund = computed(
    () => props.order.status === 'paid' || props.unappliedPayment !== null,
);

const refundOpen = ref(false);
</script>

<template>
    <Head :title="$t('Order :id', { id: order.id })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="
                $t('Order · :type :id', {
                    type: settlesTypeLabel(order.settlesType),
                    id: order.settlesId,
                })
            "
            :description="
                $t(':brand — created :time', {
                    brand: order.brand,
                    time: formatDateTime(order.createdAt) || $t('unknown'),
                })
            "
        >
            <Badge :variant="statusVariant(order.status)">{{
                orderStatusLabel(order.status)
            }}</Badge>
            <span
                v-if="order.status === 'payment_reversed' && deadline"
                class="text-sm text-text-muted"
            >
                {{ $t('— owed again until :date', { date: deadline }) }}
            </span>
            <Button
                v-if="canBookPayment(order.status)"
                variant="outline"
                @click="submitBookPayment"
            >
                <Landmark class="size-4" />
                {{ $t('Book bank transfer') }}
            </Button>
            <Button
                v-if="canRefund"
                variant="outline"
                @click="refundOpen = true"
            >
                <Undo2 class="size-4 text-danger" />
                {{
                    unappliedPayment
                        ? $t('Refund unapplied payment')
                        : $t('Refund order')
                }}
            </Button>
            <Button
                v-if="order.status === 'payment_reversed'"
                variant="ghost"
                @click="submitCancelReversed"
            >
                <XCircle class="size-4 text-danger" />
                {{ $t('Cancel order') }}
            </Button>
        </AdminPageHeader>

        <!-- #73/#7: the money goes back for the whole order or for the
             lines the operator picked, and the places a *whole* refund paid
             for are released with it — a direct registration order through
             `OrderRefunded`, a cart order's registration lines through
             `OrderLineCancelled`. A partial one releases nothing and leaves
             the order `paid`. -->
        <OrderRefundDialog
            v-model:open="refundOpen"
            :order="order"
            :lines="lines"
            :unapplied="unappliedPayment !== null"
            :creditable="invoice !== null"
        />

        <Alert v-if="unappliedPayment">
            <AlertCircle class="size-4" />
            <AlertTitle>{{ $t('Unapplied payment') }}</AlertTitle>
            <AlertDescription>
                {{
                    unappliedPayment.receivedAt
                        ? $t(
                              ':provider reported :amount :currency received :time, and this order is not paid.',
                              {
                                  provider: unappliedPayment.provider,
                                  amount: unappliedPayment.amount,
                                  currency: unappliedPayment.currency,
                                  time: formatDateTime(
                                      unappliedPayment.receivedAt,
                                  ),
                              },
                          )
                        : $t(
                              ':provider reported :amount :currency received at an unknown time, and this order is not paid.',
                              {
                                  provider: unappliedPayment.provider,
                                  amount: unappliedPayment.amount,
                                  currency: unappliedPayment.currency,
                              },
                          )
                }}
                {{
                    $t(
                        'The money moved; what happens to it is yours to decide — “Refund unapplied payment” sends it back through the account it came from, without a credit note, since a cancelled order has no document to correct.',
                    )
                }}
                <span
                    v-if="unappliedPayment.externalId"
                    class="font-mono text-xs"
                >
                    {{ unappliedPayment.externalId }}
                </span>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ $t('Lines') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        class="overflow-x-auto rounded-md border border-border"
                    >
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-border text-left text-text-muted"
                                >
                                    <th scope="col" class="p-3 font-medium">
                                        {{ $t('Item') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        {{ $t('Qty') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        {{ $t('Unit net') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        {{ $t('Tax %') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        {{ $t('Gross') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="line in lines"
                                    :key="line.id"
                                    class="border-b border-border last:border-0"
                                >
                                    <td class="p-3">{{ line.label }}</td>
                                    <td class="p-3 text-right">
                                        {{ line.quantity }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ line.unitAmountNet }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ line.taxRate }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ line.lineGross }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-border font-medium">
                                    <td class="p-3" colspan="3">
                                        {{ $t('Total') }}
                                    </td>
                                    <td class="p-3 text-right text-text-muted">
                                        {{
                                            $t('+ :amount tax', {
                                                amount: order.totalTax,
                                            })
                                        }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ order.totalGross }}
                                        {{ order.currency }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-text-muted">
                        {{
                            $t('Tax treatment: :treatment', {
                                treatment: taxTreatmentLabel(
                                    order.taxTreatment,
                                ),
                            })
                        }}
                        <template v-if="order.paidAt">
                            ·
                            {{
                                $t('paid :time', {
                                    time: formatDateTime(order.paidAt),
                                })
                            }}
                        </template>
                        <template v-if="order.provider">
                            ·
                            {{
                                $t('via :provider', {
                                    provider: order.provider,
                                })
                            }}
                        </template>
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Invoice') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3 text-sm">
                        <template v-if="invoice">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{
                                    invoice.number
                                }}</span>
                                <Button
                                    v-if="invoice.documentReady"
                                    as-child
                                    variant="outline"
                                    size="sm"
                                >
                                    <a :href="invoiceDocument(invoice.id).url">
                                        <FileText class="size-4" />
                                        PDF
                                    </a>
                                </Button>
                            </div>
                            <dl class="grid grid-cols-2 gap-1 text-text-muted">
                                <dt>{{ $t('Issued') }}</dt>
                                <dd class="text-right text-text">
                                    {{ formatCalendarDate(invoice.issuedOn) }}
                                </dd>
                                <dt>{{ $t('Due') }}</dt>
                                <dd class="text-right text-text">
                                    {{ formatCalendarDate(invoice.dueOn) }}
                                </dd>
                                <dt>{{ $t('Amount') }}</dt>
                                <dd class="text-right text-text">
                                    {{ invoice.totalGross }}
                                    {{ order.currency }}
                                </dd>
                            </dl>
                            <div class="border-t border-border pt-2">
                                <span
                                    class="text-xs font-medium text-text-muted"
                                >
                                    {{ $t('Billing party') }}
                                </span>
                                <p>{{ invoice.billingParty.name }}</p>
                                <p class="text-text-muted">
                                    {{ invoice.billingParty.email ?? '—' }}
                                </p>
                                <p class="text-text-muted">
                                    {{
                                        [
                                            invoice.billingParty.city,
                                            invoice.billingParty.countryCode,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') || '—'
                                    }}
                                </p>
                                <p
                                    v-if="invoice.billingParty.vatId"
                                    class="text-text-muted"
                                >
                                    {{
                                        $t('VAT ID :id', {
                                            id: invoice.billingParty.vatId,
                                        })
                                    }}
                                </p>
                            </div>
                        </template>
                        <p v-else class="text-text-muted">
                            {{
                                $t(
                                    'No invoice — this order settles without one.',
                                )
                            }}
                        </p>
                    </CardContent>
                </Card>

                <!--
                    #4: above the 250 € small-amount ceiling (§33 UStDV),
                    §14 Abs. 4 UStG needs the recipient's postal address on
                    the invoice — IssueInvoiceOnOrderPaid withholds the
                    Rechnung rather than issue one missing it. This is the
                    operator's retrofit: complete the party, and for an
                    already-paid order the invoice is issued the moment
                    this form is saved.
                -->
                <Card v-if="order.billingAddressRequired">
                    <CardHeader>
                        <CardTitle>{{ $t('Billing address') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3 text-sm">
                        <template v-if="showBillingAddressForm">
                            <Alert>
                                <AlertCircle class="size-4" />
                                <AlertTitle>{{
                                    $t('Address needed')
                                }}</AlertTitle>
                                <AlertDescription>
                                    {{
                                        $t(
                                            'This order is over 250 € and has no billing address, so no invoice has been issued yet. Complete it below to produce the invoice.',
                                        )
                                    }}
                                </AlertDescription>
                            </Alert>
                            <form
                                class="flex flex-col gap-3"
                                @submit.prevent="submitBillingAddress"
                            >
                                <div class="grid gap-2">
                                    <Label for="billing-recipient">{{
                                        $t('Recipient or organization')
                                    }}</Label>
                                    <Input
                                        id="billing-recipient"
                                        v-model="
                                            billingAddressForm.recipientName
                                        "
                                        :placeholder="$t('Recipient name')"
                                        :aria-invalid="
                                            billingAddressForm.errors
                                                .recipientName
                                                ? true
                                                : undefined
                                        "
                                    />
                                    <Input
                                        id="billing-organization"
                                        v-model="
                                            billingAddressForm.organizationName
                                        "
                                        :placeholder="
                                            $t('Organization (optional)')
                                        "
                                    />
                                    <InputError
                                        :message="
                                            billingAddressForm.errors
                                                .recipientName
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="billing-address-line">{{
                                        $t('Street and number')
                                    }}</Label>
                                    <Input
                                        id="billing-address-line"
                                        v-model="billingAddressForm.addressLine"
                                        :aria-invalid="
                                            billingAddressForm.errors
                                                .addressLine
                                                ? true
                                                : undefined
                                        "
                                    />
                                    <InputError
                                        :message="
                                            billingAddressForm.errors
                                                .addressLine
                                        "
                                    />
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="grid gap-2">
                                        <Label for="billing-postal-code">{{
                                            $t('Postal code')
                                        }}</Label>
                                        <Input
                                            id="billing-postal-code"
                                            v-model="
                                                billingAddressForm.postalCode
                                            "
                                            :aria-invalid="
                                                billingAddressForm.errors
                                                    .postalCode
                                                    ? true
                                                    : undefined
                                            "
                                        />
                                        <InputError
                                            :message="
                                                billingAddressForm.errors
                                                    .postalCode
                                            "
                                        />
                                    </div>
                                    <div class="col-span-2 grid gap-2">
                                        <Label for="billing-city">{{
                                            $t('City')
                                        }}</Label>
                                        <Input
                                            id="billing-city"
                                            v-model="billingAddressForm.city"
                                            :aria-invalid="
                                                billingAddressForm.errors.city
                                                    ? true
                                                    : undefined
                                            "
                                        />
                                        <InputError
                                            :message="
                                                billingAddressForm.errors.city
                                            "
                                        />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="grid gap-2">
                                        <Label for="billing-country-code">{{
                                            $t('Country code')
                                        }}</Label>
                                        <Input
                                            id="billing-country-code"
                                            v-model="
                                                billingAddressForm.countryCode
                                            "
                                            maxlength="2"
                                            class="uppercase"
                                            :aria-invalid="
                                                billingAddressForm.errors
                                                    .countryCode
                                                    ? true
                                                    : undefined
                                            "
                                        />
                                        <InputError
                                            :message="
                                                billingAddressForm.errors
                                                    .countryCode
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="billing-vat-id">{{
                                            $t('VAT ID (optional)')
                                        }}</Label>
                                        <Input
                                            id="billing-vat-id"
                                            v-model="billingAddressForm.vatId"
                                        />
                                        <InputError
                                            :message="
                                                billingAddressForm.errors.vatId
                                            "
                                        />
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <Button
                                        type="submit"
                                        :disabled="
                                            billingAddressForm.processing
                                        "
                                    >
                                        {{ $t('Save billing address') }}
                                    </Button>
                                    <span
                                        v-if="billingAddressForm.isDirty"
                                        role="status"
                                        class="text-sm text-warning"
                                    >
                                        {{ $t('Unsaved changes') }}
                                    </span>
                                </div>
                            </form>
                        </template>
                        <template v-else-if="order.billingParty">
                            <p>
                                {{
                                    order.billingParty.organizationName ??
                                    order.billingParty.recipientName ??
                                    '—'
                                }}
                            </p>
                            <p class="text-text-muted">
                                {{
                                    [
                                        order.billingParty.addressLine,
                                        [
                                            order.billingParty.postalCode,
                                            order.billingParty.city,
                                        ]
                                            .filter(Boolean)
                                            .join(' '),
                                        order.billingParty.countryCode,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || '—'
                                }}
                            </p>
                        </template>
                        <p v-else class="text-text-muted">
                            {{ $t('No billing party on this order.') }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="creditNotes.length">
                    <CardHeader>
                        <CardTitle>{{ $t('Credit notes') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2 text-sm">
                        <div
                            v-for="note in creditNotes"
                            :key="note.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <div>
                                <p class="font-medium">{{ note.number }}</p>
                                <p class="text-xs text-text-muted">
                                    {{ refundReasonLabel(note.reasonCode) }} ·
                                    {{ formatCalendarDate(note.issuedOn) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    >-{{ note.totalGross }}
                                    {{ order.currency }}</span
                                >
                                <Button
                                    v-if="note.documentReady"
                                    as-child
                                    variant="outline"
                                    size="sm"
                                >
                                    <a
                                        :href="creditNoteDocument(note.id).url"
                                        :aria-label="`Download credit note ${note.number} PDF`"
                                    >
                                        <FileText class="size-4" />
                                        PDF
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ $t('Payments') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <p v-if="payments.length === 0" class="text-sm text-text-muted">
                    {{ $t('No payment attempts yet.') }}
                </p>
                <div
                    v-else
                    class="overflow-x-auto rounded-md border border-border"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-text-muted"
                            >
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Provider') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Status') }}
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    {{ $t('Amount') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Reference') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('When') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="payment in payments"
                                :key="payment.id"
                                class="border-b border-border last:border-0"
                            >
                                <td class="p-3">{{ payment.provider }}</td>
                                <td class="p-3">
                                    <Badge
                                        :variant="
                                            paymentVariant(payment.status)
                                        "
                                    >
                                        {{ paymentStatusLabel(payment.status) }}
                                    </Badge>
                                    <p
                                        v-if="payment.failureReason"
                                        class="mt-1 text-xs text-text-muted"
                                    >
                                        {{ payment.failureReason }}
                                    </p>
                                </td>
                                <td class="p-3 text-right">
                                    {{ payment.amount }} {{ payment.currency }}
                                </td>
                                <td class="p-3 text-text-muted">
                                    {{ payment.externalId ?? '—' }}
                                </td>
                                <td class="p-3 text-text-muted">
                                    {{
                                        formatDateTime(
                                            payment.receivedAt ??
                                                payment.failedAt,
                                        ) || '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card v-if="reversals.length">
            <CardHeader>
                <CardTitle>{{ $t('Reversals') }}</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p class="text-sm text-text-muted">
                    {{
                        $t(
                            'Money that left again after it had settled (ADR-0057). The sale stands and the invoice is unchanged; a final reversal revives the receivable until the deadline below.',
                        )
                    }}
                </p>
                <div class="overflow-x-auto rounded-md border border-border">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                $t('Reversals recorded against this order')
                            }}
                        </caption>
                        <thead>
                            <tr
                                class="border-b border-border text-left text-text-muted"
                            >
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Kind') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Status') }}
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    {{ $t('Amount') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Reference') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Reason') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('Owed again until') }}
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    {{ $t('When') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="reversal in reversals"
                                :key="reversal.id"
                                class="border-b border-border last:border-0"
                            >
                                <td class="p-3">
                                    {{ reversalKindLabel(reversal.kind) }}
                                    <span class="text-xs text-text-muted">
                                        · {{ reversal.provider }}
                                    </span>
                                </td>
                                <td class="p-3">
                                    <Badge
                                        :variant="
                                            reversal.status === 'final'
                                                ? 'destructive'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            reversalStatusLabel(reversal.status)
                                        }}
                                    </Badge>
                                </td>
                                <td class="p-3 text-right">
                                    {{ reversal.amount }}
                                    {{ reversal.currency }}
                                    <!-- ADR-0007: the provider's fee is the
                                         organizer's own cost, never a line on
                                         our invoice — and only Stripe
                                         disputes report one. -->
                                    <p
                                        v-if="reversal.feeAmount"
                                        class="text-xs text-text-muted"
                                    >
                                        {{
                                            $t('+ :amount :currency fee', {
                                                amount: reversal.feeAmount,
                                                currency: reversal.currency,
                                            })
                                        }}
                                    </p>
                                </td>
                                <td
                                    class="p-3 font-mono text-xs text-text-muted"
                                >
                                    {{ reversal.providerReference }}
                                </td>
                                <td class="p-3 text-text-muted">
                                    {{ reversal.reasonCode ?? '—' }}
                                </td>
                                <td class="p-3 text-text-muted">
                                    {{
                                        formatCalendarDate(
                                            reversal.repayDueOn,
                                        ) || '—'
                                    }}
                                </td>
                                <td class="p-3 text-text-muted">
                                    {{
                                        formatDateTime(
                                            reversal.finalizedAt ??
                                                reversal.occurredAt,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
