<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertCircle, FileText, Landmark, Undo2, XCircle } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { document as creditNoteDocument } from '@/routes/admin/credit-notes';
import { document as invoiceDocument } from '@/routes/admin/invoices';
import { bookPayment, cancelReversed, refund } from '@/routes/admin/orders';
import type { OrderReversal, UnappliedPayment } from './reversals';
import {
    canBookPayment,
    orderStatusLabel,
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
    };
    lines: {
        id: string;
        label: string;
        quantity: number;
        unitAmountNet: string;
        taxRate: string;
        lineNet: string;
        lineGross: string;
    }[];
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
            'Cancel this order? Where an invoice exists it will be credited, and the reversed payment will no longer be chased.',
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
 * #73: the whole-order refund, the first console surface `RefundOrder` has
 * ever had (ADR-0037). A dialog rather than the plain `confirm()` the cancel
 * button uses, because this one collects something.
 *
 * Where what it collects ends up, precisely: the note is stored on the
 * credit-note record (`credit_notes.reason_note`) and the code travels on
 * `OrderRefunded` — and both are written in full to the console's audit
 * entry, which is where an operator reads back who refunded and why. Neither
 * is printed on the Gutschrift itself: that document says "Grund: Erstattung"
 * for every refund (`credit-notes/document.blade.php` renders `reason_code`,
 * which `RecordRefund` always sets to `refund`). Printing the operator's
 * reason on the document is a follow-up, not something this dialog may
 * promise.
 *
 * The vocabulary is `Konventix.Commerce.Enums.RefundReason`, the generated
 * type, and not a hand-kept copy of it (ADR-0009, whole-branch review
 * finding 10): `EnumTransformer` is registered over every module's `Enums`
 * directory, so the enum is emitted whether or not a Data class references
 * it. A `Record` keyed by that type is exhaustive — adding, renaming or
 * removing a case fails `npm run types:check` here rather than reaching an
 * operator as a form error.
 */
const refundReasonLabels: Record<
    Konventix.Commerce.Enums.RefundReason,
    string
> = {
    'customer-request': 'The customer asked for it',
    'event-cancelled': 'The event was cancelled',
    'duplicate-payment': 'Paid twice',
    goodwill: 'Goodwill',
};

const refundReasons = Object.entries(refundReasonLabels).map(
    ([value, label]) => ({
        value: value as Konventix.Commerce.Enums.RefundReason,
        label,
    }),
);

/**
 * Two orders can be refunded (#6, ADR-0057 point 9): a paid one, and a
 * cancelled one still holding money nobody could apply — the PSP success
 * that landed after a sweep had already called the order off. The second is
 * `RefundOrder`'s provider leg without a credit note: the money goes back
 * through the account it came from, the order stays cancelled, and no
 * document is written, because a cancelled order has none to correct.
 *
 * Until this the console could only *show* that money and send the operator
 * to the provider's dashboard, which is the manual step the issue was filed
 * about.
 */
const canRefund = computed(
    () => props.order.status === 'paid' || props.unappliedPayment !== null,
);

const refundOpen = ref(false);

/**
 * Deliberately no pre-picked reason. `#[Required]` on
 * `RefundOrderData::$reasonCode` refuses an empty submission and the 422
 * lands on the field's own `InputError` (ADR-0055): defaulting to the most
 * common answer would let an irreversible money movement be justified by an
 * opinion nobody formed. It also keeps `isDirty` honest, so ADR-0038's
 * "Unsaved changes" note appears exactly when something has been entered.
 */
const refundForm = useForm<{
    reasonCode: Konventix.Commerce.Enums.RefundReason | null;
    reasonNote: string | null;
}>({
    reasonCode: null,
    reasonNote: null,
});

// Only while the dialog is open (ADR-0038): a closed dialog's untouched
// draft is not unsaved work, the same scoping `products/Index.vue` uses.
useUnsavedChanges(() => refundOpen.value && refundForm.isDirty);

/**
 * Every refusal `RefundOrder` makes — the ADR-0037 check-in guard, "only a
 * paid order can be refunded", a provider with nothing refundable — arrives
 * as a validation error under `order`, which is not one of this form's own
 * fields, so it is read by name like `products/Index.vue` reads `brand`.
 */
const refundRefusal = computed(
    () => (refundForm.errors as Record<string, string>).order,
);

function submitRefund(): void {
    refundForm
        // A textarea that was typed into and then emptied posts `''`; the
        // note the credit note keeps is either words or nothing.
        .transform((data) => ({
            ...data,
            reasonNote: data.reasonNote || null,
        }))
        .post(refund(props.order.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                refundForm.reset();
                refundOpen.value = false;
                returnFocusAfterRefund();
            },
        });
}

/**
 * Where the keyboard goes once the refund has gone through (ADR-0055 point
 * 1, whole-branch review finding 6).
 *
 * The dialog restores focus to the trigger it captured, and that trigger is
 * `v-if="order.status === 'paid'"` — the refund has just flipped the status,
 * so reka-ui calls `.focus()` on a detached node and focus lands on `<body>`.
 * A keyboard operator was silently returned to the top of the document
 * after the console's most consequential action. `main#main-content` is the
 * layout's own landing place for exactly this (`.ai/rules/js.md`, #181), it
 * carries `tabindex="-1"`, and it is where the refreshed order — now
 * "Refunded", with its credit note — is.
 *
 * After `nextTick`, because the props that remove the trigger have not been
 * applied when `onSuccess` runs.
 */
function returnFocusAfterRefund(): void {
    void nextTick(() => {
        document.getElementById('main-content')?.focus();
    });
}
</script>

<template>
    <Head :title="`Order ${order.id}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Order · ${order.settlesType} ${order.settlesId}`"
            :description="`${order.brand} — created ${formatDateTime(order.createdAt) || 'unknown'}`"
        >
            <Badge :variant="statusVariant(order.status)">{{
                orderStatusLabel(order.status)
            }}</Badge>
            <span
                v-if="order.status === 'payment_reversed' && deadline"
                class="text-sm text-text-muted"
            >
                — owed again until {{ deadline }}
            </span>
            <Button
                v-if="canBookPayment(order.status)"
                variant="outline"
                @click="submitBookPayment"
            >
                <Landmark class="size-4" />
                Book bank transfer
            </Button>
            <Button
                v-if="canRefund"
                variant="outline"
                @click="refundOpen = true"
            >
                <Undo2 class="size-4 text-danger" />
                {{
                    unappliedPayment
                        ? 'Refund unapplied payment'
                        : 'Refund whole order'
                }}
            </Button>
            <Button
                v-if="order.status === 'payment_reversed'"
                variant="ghost"
                @click="submitCancelReversed"
            >
                <XCircle class="size-4 text-danger" />
                Cancel order
            </Button>
        </AdminPageHeader>

        <!-- #73: the money goes back for the whole order, and the places it
             paid for are released with it — a direct registration order
             through `OrderRefunded`, a cart order's registration lines
             through `OrderLineCancelled` (whole-branch review finding 3).
             Partial refunds and transfers are the issue's other open
             items. -->
        <Dialog
            :open="refundOpen"
            @update:open="
                (value: boolean) => {
                    refundOpen = value;
                    if (!value) {
                        refundForm.reset();
                        refundForm.clearErrors();
                    }
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        unappliedPayment
                            ? 'Refund unapplied payment'
                            : 'Refund whole order'
                    }}</DialogTitle>
                    <DialogDescription>
                        Return the full {{ order.totalGross }}
                        {{ order.currency }} through the account that took it.
                        Where an invoice exists it is credited. The reason and
                        the note below are recorded against this order for you
                        and the next operator — they are not printed on the
                        credit note the payer receives. This cannot be undone
                        here.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitRefund"
                >
                    <InputError :message="refundRefusal" />
                    <div class="grid gap-2">
                        <Label for="refund-reason">Reason</Label>
                        <select
                            id="refund-reason"
                            v-model="refundForm.reasonCode"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            :aria-invalid="
                                refundForm.errors.reasonCode ? true : undefined
                            "
                            :aria-describedby="
                                refundForm.errors.reasonCode
                                    ? 'refund-reason-error'
                                    : undefined
                            "
                        >
                            <!-- Disabled, so it can be read but not chosen:
                                 the operator picks a reason or the server
                                 refuses the refund. -->
                            <option :value="null" disabled>
                                Choose a reason
                            </option>
                            <option
                                v-for="reason in refundReasons"
                                :key="reason.value"
                                :value="reason.value"
                            >
                                {{ reason.label }}
                            </option>
                        </select>
                        <InputError
                            id="refund-reason-error"
                            :message="refundForm.errors.reasonCode"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="refund-note">Note (optional)</Label>
                        <textarea
                            id="refund-note"
                            v-model="refundForm.reasonNote"
                            rows="3"
                            class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            :aria-invalid="
                                refundForm.errors.reasonNote ? true : undefined
                            "
                            :aria-describedby="
                                refundForm.errors.reasonNote
                                    ? 'refund-note-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="refund-note-error"
                            :message="refundForm.errors.reasonNote"
                        />
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="refundForm.processing"
                            @click="refundOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="refundForm.processing"
                        >
                            Refund {{ order.totalGross }} {{ order.currency }}
                        </Button>
                        <span
                            v-if="refundForm.isDirty"
                            role="status"
                            class="text-sm text-warning"
                        >
                            Unsaved changes
                        </span>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Alert v-if="unappliedPayment">
            <AlertCircle class="size-4" />
            <AlertTitle>Unapplied payment</AlertTitle>
            <AlertDescription>
                {{ unappliedPayment.provider }} reported
                {{ unappliedPayment.amount }} {{ unappliedPayment.currency }}
                received
                {{
                    formatDateTime(unappliedPayment.receivedAt) ||
                    'at an unknown time'
                }}, and this order is not paid. The money moved; what happens to
                it is yours to decide — "Refund unapplied payment" sends it back
                through the account it came from, without a credit note, since a
                cancelled order has no document to correct.
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
                    <CardTitle>Lines</CardTitle>
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
                                        Item
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        Qty
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        Unit net
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        Tax %
                                    </th>
                                    <th
                                        scope="col"
                                        class="p-3 text-right font-medium"
                                    >
                                        Gross
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
                                    <td class="p-3" colspan="3">Total</td>
                                    <td class="p-3 text-right text-text-muted">
                                        + {{ order.totalTax }} tax
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
                        Tax treatment: {{ order.taxTreatment }}
                        <template v-if="order.paidAt">
                            · paid {{ formatDateTime(order.paidAt) }}
                        </template>
                        <template v-if="order.provider">
                            · via {{ order.provider }}
                        </template>
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Invoice</CardTitle>
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
                                <dt>Issued</dt>
                                <dd class="text-right text-text">
                                    {{ formatCalendarDate(invoice.issuedOn) }}
                                </dd>
                                <dt>Due</dt>
                                <dd class="text-right text-text">
                                    {{ formatCalendarDate(invoice.dueOn) }}
                                </dd>
                                <dt>Amount</dt>
                                <dd class="text-right text-text">
                                    {{ invoice.totalGross }}
                                    {{ order.currency }}
                                </dd>
                            </dl>
                            <div class="border-t border-border pt-2">
                                <span
                                    class="text-xs font-medium text-text-muted"
                                >
                                    Billing party
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
                                    VAT {{ invoice.billingParty.vatId }}
                                </p>
                            </div>
                        </template>
                        <p v-else class="text-text-muted">
                            No invoice — this order settles without one.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="creditNotes.length">
                    <CardHeader>
                        <CardTitle>Credit notes</CardTitle>
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
                                    {{ note.reasonCode }} ·
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
                <CardTitle>Payments</CardTitle>
            </CardHeader>
            <CardContent>
                <p v-if="payments.length === 0" class="text-sm text-text-muted">
                    No payment attempts yet.
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
                                    Provider
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Amount
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Reference
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    When
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
                                        {{ payment.status }}
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
                <CardTitle>Reversals</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p class="text-sm text-text-muted">
                    Money that left again after it had settled (ADR-0057). The
                    sale stands and the invoice is unchanged; a final reversal
                    revives the receivable until the deadline below.
                </p>
                <div class="overflow-x-auto rounded-md border border-border">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Reversals recorded against this order
                        </caption>
                        <thead>
                            <tr
                                class="border-b border-border text-left text-text-muted"
                            >
                                <th scope="col" class="p-3 font-medium">
                                    Kind
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="p-3 text-right font-medium"
                                >
                                    Amount
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Reference
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Reason
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    Owed again until
                                </th>
                                <th scope="col" class="p-3 font-medium">
                                    When
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
                                        {{ reversal.status }}
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
                                        + {{ reversal.feeAmount }}
                                        {{ reversal.currency }} fee
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
