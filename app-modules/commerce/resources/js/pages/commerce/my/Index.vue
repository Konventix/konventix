<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { show as showOrder } from '@/routes/commerce/mine';
import { show as showRegistration } from '@/routes/registration/mine';
import { orderStatusLabel } from '../../../lib/labels';

/**
 * "What do I owe" — the payer's own list (ADR-0044). The per-registration
 * page already shows one registration's settlement through Commerce's slot;
 * this is the other direction, everything on this brand under one account.
 */
type Order = Konventix.Commerce.Data.MyOrderData;

const props = defineProps<{ orders: Order[] }>();

const statusTone: Record<string, string> = {
    paid: 'bg-success/15 text-success',
    pending: 'bg-warning/15 text-warning-contrast',
    cancelled: 'bg-danger/15 text-danger',
    refunded: 'bg-subtle text-text',
};

/**
 * The day an order was placed, in the reader's own zone and the operator's
 * own format (#243, #242) — never a formatter of this page's own, which is
 * how the console's "Placed" column came to disagree with this one.
 */
function formatPlacedOn(value: string): string {
    return formatDateTime(value, { dateStyle: 'medium', timeStyle: undefined });
}
</script>

<template>
    <Head :title="$t('My orders')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            :title="$t('My orders')"
            :description="
                $t('Everything you have been charged for with this brand')
            "
        />

        <p v-if="props.orders.length === 0" class="text-sm text-text-muted">
            {{ $t('You have no orders on this brand yet.') }}
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="order in props.orders"
                :key="order.id"
                class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4 sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <Link
                        v-if="order.settlesType === 'registration'"
                        :href="showRegistration(order.settlesId).url"
                        class="font-medium text-text underline-offset-4 hover:underline"
                    >
                        {{ order.settlesLabel }}
                    </Link>
                    <span v-else class="font-medium text-text">
                        {{ order.settlesLabel }}
                    </span>
                    <span class="text-sm text-text-muted">
                        {{ formatPlacedOn(order.placedAt) }}
                        <template v-if="order.invoiceNumber">
                            ·
                            {{
                                $t('Invoice :number', {
                                    number: order.invoiceNumber,
                                })
                            }}
                        </template>
                    </span>
                    <span class="flex flex-wrap items-center gap-2 pt-1">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="
                                statusTone[order.status] ??
                                'bg-subtle text-text'
                            "
                        >
                            {{ orderStatusLabel(order.status) }}
                        </span>
                        <span class="text-sm font-medium text-text">
                            {{ order.totalGross }} {{ order.currency }}
                        </span>
                    </span>

                    <!--
                        A cart order settles a mix of products and
                        registrations (ADR-0047) — the summary line above
                        does not say enough on its own, so its lines get
                        their own breakdown. A single-line registration
                        order already has its link on the summary line, so
                        this stays specific to carts.
                    -->
                    <ul
                        v-if="
                            order.settlesType === 'cart' &&
                            order.lines.length > 0
                        "
                        class="mt-1 flex flex-col gap-0.5 border-t border-border pt-1 text-sm text-text-muted"
                    >
                        <li
                            v-for="(line, index) in order.lines"
                            :key="index"
                            class="flex items-center justify-between gap-2"
                        >
                            <span>
                                {{ line.quantity }}×
                                <Link
                                    v-if="line.registrationUrl"
                                    :href="line.registrationUrl"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ line.label }}
                                </Link>
                                <span v-else>{{ line.label }}</span>
                            </span>
                            <span
                                >{{ line.lineGross }} {{ order.currency }}</span
                            >
                        </li>
                    </ul>
                </div>

                <div
                    class="flex shrink-0 flex-wrap items-center justify-end gap-2"
                >
                    <!--
                        The order's own page (bugfix round 2026-09-21, Task
                        3) — the same page a hosted checkout returns to, so
                        what the payer saw after paying is reachable again
                        from here. A real link, so it is keyboard-reachable
                        (.ai/rules/js.md).
                    -->
                    <Button as-child variant="outline" size="sm">
                        <Link :href="showOrder(order.id).url">{{
                            $t('Details')
                        }}</Link>
                    </Button>
                    <Button v-if="order.payUrl" as-child size="sm">
                        <a :href="order.payUrl">{{ $t('Pay now') }}</a>
                    </Button>
                    <Button
                        v-if="order.documentUrl"
                        as-child
                        variant="secondary"
                        size="sm"
                    >
                        <a :href="order.documentUrl">
                            <FileText class="size-4" />
                            {{
                                order.invoicePaid
                                    ? $t('Invoice (paid)')
                                    : $t('Invoice')
                            }}
                        </a>
                    </Button>
                    <!--
                        A refund of a paid order leaves a credit note beside
                        the invoice it corrects (ADR-0048 point 2) — one
                        secondary button per credit note, same shape as the
                        Invoice button above, linking to the document only
                        once it has been generated.
                    -->
                    <template
                        v-for="creditNote in order.creditNotes"
                        :key="creditNote.number"
                    >
                        <Button
                            v-if="creditNote.documentUrl"
                            as-child
                            variant="secondary"
                            size="sm"
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
            </li>
        </ul>
    </div>
</template>
