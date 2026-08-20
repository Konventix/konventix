<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileText, Landmark } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { document as invoiceDocument } from '@/routes/admin/invoices';
import { bookPayment } from '@/routes/admin/orders';

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
    }[];
};

const props = defineProps<Props>();

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'paid') {
        return 'default';
    }

    return status === 'cancelled' ? 'destructive' : 'secondary';
}

function submitBookPayment(): void {
    router.post(bookPayment(props.order.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Order ${order.id}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Order · ${order.settlesType} ${order.settlesId}`"
            :description="`${order.brand} — created ${order.createdAt ?? 'unknown'}`"
        >
            <Badge :variant="statusVariant(order.status)">{{
                order.status
            }}</Badge>
            <Button
                v-if="!order.paidAt && order.status !== 'cancelled'"
                variant="outline"
                @click="submitBookPayment"
            >
                <Landmark class="size-4" />
                Book bank transfer
            </Button>
        </AdminPageHeader>

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
                                    <th class="p-3 font-medium">Item</th>
                                    <th class="p-3 text-right font-medium">
                                        Qty
                                    </th>
                                    <th class="p-3 text-right font-medium">
                                        Unit net
                                    </th>
                                    <th class="p-3 text-right font-medium">
                                        Tax %
                                    </th>
                                    <th class="p-3 text-right font-medium">
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
                            · paid {{ order.paidAt }}
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
                                    {{ invoice.issuedOn }}
                                </dd>
                                <dt>Due</dt>
                                <dd class="text-right text-text">
                                    {{ invoice.dueOn }}
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
                            class="flex items-center justify-between"
                        >
                            <div>
                                <p class="font-medium">{{ note.number }}</p>
                                <p class="text-xs text-text-muted">
                                    {{ note.reasonCode }} · {{ note.issuedOn }}
                                </p>
                            </div>
                            <span
                                >-{{ note.totalGross }}
                                {{ order.currency }}</span
                            >
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
                                <th class="p-3 font-medium">Provider</th>
                                <th class="p-3 font-medium">Status</th>
                                <th class="p-3 text-right font-medium">
                                    Amount
                                </th>
                                <th class="p-3 font-medium">Reference</th>
                                <th class="p-3 font-medium">When</th>
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
                                            payment.status === 'succeeded'
                                                ? 'default'
                                                : payment.status === 'failed'
                                                  ? 'destructive'
                                                  : 'secondary'
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
                                        payment.receivedAt ??
                                        payment.failedAt ??
                                        '—'
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
