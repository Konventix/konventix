<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CreditCard, FileText, ShoppingCart } from '@lucide/vue';
import { computed } from 'vue';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { orderStatusLabel } from '../lib/labels';

/**
 * Commerce's contribution to Registration's account page: what this
 * registration cost, whether it is settled, and the invoice if one was
 * issued. Until this existed the pay link had no inbound route in the UI at
 * all — the checkout page worked and nobody could reach it.
 *
 * `settlement` is null for a free registration, which creates no order at
 * all (ADR-0026), and that is the common case rather than an error state —
 * so it renders nothing rather than "no payment required", which would put
 * a money heading on a page about a free ticket.
 *
 * The invoice download is a temporary signed link minted server-side. It
 * expires; a page left open long enough will need a reload, which is the
 * correct trade for a document carrying a billing address.
 *
 * `cart` is the second half of the same question (#210): with
 * `commerce:shop` on, an `instant` registration settles into a cart line
 * rather than an order (ADR-0047 point 3), and "no order" then means "pay
 * it in the cart", not "nothing to pay". Exactly one of the two props is
 * ever set — the server picks — and both null still renders nothing, which
 * is the right answer for a free registration.
 *
 * `orderUrl` on either prop is the order's own page (bugfix round
 * 2026-09-21, Task 17b): the invoice number and an "Order details" link
 * lead there, and a cart that became an order links that order rather than
 * only the list.
 */
type Settlement = Konventix.Commerce.Data.RegistrationSettlementData;
type CartSettlement = Konventix.Commerce.Data.RegistrationCartSettlementData;

const props = defineProps<{
    settlement: Settlement | null;
    cart: CartSettlement | null;
}>();

/**
 * The hold's deadline in the reader's own zone (ADR-0055 point 4: a time
 * limit is visible and explained, and never silently extended).
 */
const cartExpiresLabel = computed<string>(() =>
    // Date *and* time: a hold that says "until 21:35" and nothing else is
    // ambiguous the moment the page is read the next morning. Same markup
    // as the registration timestamps (#208) — a `<time datetime>` carrying
    // the instant, with the reader's own wall clock inside it.
    formatDateTime(props.cart?.expiresAt, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const statusTone: Record<string, string> = {
    paid: 'bg-success/15 text-success',
    cancelled: 'bg-danger/15 text-danger',
    refunded: 'bg-danger/15 text-danger',
    payment_reversed: 'bg-danger/15 text-danger',
    pending: 'bg-warning/15 text-warning-contrast',
};

/**
 * ADR-0057 point 3 (#197): the money left again after it had settled, so
 * the amount is owed once more and the pay button below is live. The status
 * word alone would read as `Payment_reversed`, which says nothing about
 * what the reader is supposed to do next.
 */
const reversed = computed(
    () => props.settlement?.status === 'payment_reversed',
);
</script>

<template>
    <section
        v-if="props.cart"
        class="flex flex-col gap-3"
        aria-labelledby="registration-cart-settlement"
    >
        <h2 id="registration-cart-settlement" class="text-lg font-medium">
            {{ $t('Payment') }}
        </h2>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-raised p-4"
        >
            <span class="text-lg font-medium">
                {{ props.cart.totalGross }} {{ props.cart.currency }}
            </span>

            <p
                v-if="
                    props.cart.status === 'in_cart' &&
                    props.cart.expiresAt &&
                    cartExpiresLabel
                "
                class="text-sm text-text-muted"
                role="status"
            >
                {{ $t('Waiting in your cart — reserved until') }}
                <time :datetime="props.cart.expiresAt">{{
                    cartExpiresLabel
                }}</time
                >.
            </p>
            <p
                v-else-if="props.cart.status === 'in_cart'"
                class="text-sm text-text-muted"
                role="status"
            >
                {{ $t('Waiting in your cart.') }}
            </p>
            <p
                v-else-if="props.cart.status === 'checkout_started'"
                class="text-sm text-text-muted"
                role="status"
            >
                {{
                    props.cart.orderUrl
                        ? $t('Checkout started — finish it from your order.')
                        : $t('Checkout started — finish it from your orders.')
                }}
            </p>
            <p v-else class="text-sm text-text-muted" role="status">
                {{
                    $t(
                        'Your cart reservation ran out before this registration was paid, so it was released. Register again to take the spot.',
                    )
                }}
            </p>

            <span class="flex-1" />

            <Link
                v-if="props.cart.cartUrl"
                :href="props.cart.cartUrl"
                class="inline-flex items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
            >
                <ShoppingCart class="size-4" />
                {{ $t('Pay now in the cart') }}
            </Link>
            <Link
                v-else-if="props.cart.orderUrl"
                :href="props.cart.orderUrl"
                class="inline-flex items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-subtle"
            >
                <CreditCard class="size-4" />
                {{ $t('Go to your order') }}
            </Link>
            <Link
                v-else-if="props.cart.ordersUrl"
                :href="props.cart.ordersUrl"
                class="inline-flex items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-subtle"
            >
                <CreditCard class="size-4" />
                {{ $t('Go to your orders') }}
            </Link>
        </div>
    </section>

    <section
        v-else-if="props.settlement"
        class="flex flex-col gap-3"
        aria-labelledby="registration-settlement"
    >
        <h2 id="registration-settlement" class="text-lg font-medium">
            {{ $t('Payment') }}
        </h2>

        <!--
            Above the amount row rather than inside it (#197 review finding
            7): the row is a wrapping flex line, and a sentence dropped into
            it shares a line with the status badge and pushes "Pay now" off
            the end.
        -->
        <p
            v-if="reversed && props.settlement.repayDueOn"
            role="status"
            class="text-sm text-danger"
        >
            {{ $t('Payment reversed — this amount is owed again until') }}
            <time :datetime="props.settlement.repayDueOn">{{
                formatCalendarDate(props.settlement.repayDueOn)
            }}</time
            >.
        </p>
        <p v-else-if="reversed" role="status" class="text-sm text-danger">
            {{ $t('Payment reversed — this amount is owed again.') }}
        </p>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-raised p-4"
        >
            <span class="text-lg font-medium">
                {{ props.settlement.totalGross }}
                {{ props.settlement.currency }}
            </span>
            <span
                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                :class="
                    statusTone[props.settlement.status] ?? 'bg-subtle text-text'
                "
            >
                {{ orderStatusLabel(props.settlement.status) }}
            </span>
            <Link
                v-if="props.settlement.orderUrl"
                :href="props.settlement.orderUrl"
                class="text-sm text-text-muted underline underline-offset-4 hover:text-text"
            >
                {{ $t('Order details') }}
            </Link>

            <span class="flex-1" />

            <Link
                v-if="props.settlement.payUrl"
                :href="props.settlement.payUrl"
                class="inline-flex items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
            >
                <CreditCard class="size-4" />
                {{ $t('Pay now') }}
            </Link>
        </div>

        <dl
            v-if="props.settlement.invoiceNumber"
            class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]"
        >
            <dt class="text-text-muted">{{ $t('Invoice') }}</dt>
            <dd>
                <Link
                    v-if="props.settlement.orderUrl"
                    :href="props.settlement.orderUrl"
                    class="underline-offset-4 hover:underline"
                >
                    {{ props.settlement.invoiceNumber }}
                </Link>
                <template v-else>{{ props.settlement.invoiceNumber }}</template>
            </dd>
            <dt class="text-text-muted">{{ $t('Issued') }}</dt>
            <dd>{{ formatCalendarDate(props.settlement.issuedOn) }}</dd>
            <dt class="text-text-muted">{{ $t('Due') }}</dt>
            <dd>{{ formatCalendarDate(props.settlement.dueOn) }}</dd>
        </dl>

        <!--
            A plain anchor, not an Inertia <Link>: this streams a PDF, and an
            Inertia visit would expect a page component back.
        -->
        <a
            v-if="props.settlement.documentUrl"
            :href="props.settlement.documentUrl"
            class="inline-flex w-fit items-center gap-2 text-sm text-text-muted underline underline-offset-4 hover:text-text"
        >
            <FileText class="size-4" />
            {{ $t('Download invoice (PDF)') }}
        </a>
    </section>
</template>
