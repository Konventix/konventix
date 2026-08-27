<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CreditCard, Minus, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { checkout as checkoutCart } from '@/routes/commerce/cart';
import {
    destroy as destroyLine,
    update as updateLine,
} from '@/routes/commerce/cart/lines';

/**
 * The person-owned cart (ADR-0047, Task 11): line list with quantity
 * controls and remove, a tax preview of the totals checkout recomputes for
 * real, and one button per offered settlement account — the same pattern
 * `pay/Show.vue` already renders for the direct registration checkout.
 *
 * No dirty guard (ADR-0038): every change here — quantity, remove, add —
 * is its own immediate request with no draft state a navigation could lose.
 */
type CartLine = Konventix.Commerce.Data.CartLineData;
type Cart = Konventix.Commerce.Data.CartData;

const props = defineProps<{
    cart: Cart;
}>();

const page = usePage();
const accountError = computed(() => page.props.errors.account);
const quantityError = computed(() => page.props.errors.quantity);

// Only the line currently being changed disables its own controls, so the
// rest of the cart stays usable while one request is in flight.
const busyLineId = ref<string | null>(null);
const startingAccount = ref<string | null>(null);

function changeQuantity(line: CartLine, quantity: number): void {
    if (
        quantity < 1 ||
        (line.maxQuantity !== null && quantity > line.maxQuantity)
    ) {
        return;
    }

    busyLineId.value = line.id;
    router.patch(
        updateLine(line.id).url,
        { quantity },
        { preserveScroll: true, onFinish: () => (busyLineId.value = null) },
    );
}

function removeLine(line: CartLine): void {
    busyLineId.value = line.id;
    router.delete(destroyLine(line.id).url, {
        preserveScroll: true,
        onFinish: () => (busyLineId.value = null),
    });
}

function checkout(accountId: string): void {
    startingAccount.value = accountId;
    router.post(
        checkoutCart().url,
        { account: accountId },
        { onFinish: () => (startingAccount.value = null) },
    );
}

const expiresInMinutes = computed<number | null>(() => {
    if (props.cart.expiresAt === null) {
        return null;
    }

    const ms = new Date(props.cart.expiresAt).getTime() - Date.now();

    return Math.max(0, Math.ceil(ms / 60_000));
});
</script>

<template>
    <Head title="Your cart" />

    <div
        class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <Heading as="h1" title="Your cart" />

        <p v-if="cart.lines.length === 0" class="text-sm text-text-muted">
            Your cart is empty.
            <Link href="/shop" class="underline underline-offset-4">
                Browse the shop
            </Link>
        </p>

        <template v-else>
            <p
                v-if="expiresInMinutes !== null"
                role="status"
                class="text-sm text-text-muted"
            >
                Expires in {{ expiresInMinutes }} minute<span
                    v-if="expiresInMinutes !== 1"
                    >s</span
                >
            </p>

            <ul class="flex flex-col gap-3">
                <li
                    v-for="line in cart.lines"
                    :key="line.id"
                    class="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface-raised p-4"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-medium text-text">{{
                            line.label
                        }}</span>
                        <span class="text-sm text-text-muted">
                            {{ line.unitAmountNet }} € each
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div
                            v-if="line.canChangeQuantity"
                            class="flex items-center gap-1"
                        >
                            <button
                                type="button"
                                class="inline-flex size-7 items-center justify-center rounded-md border border-border text-text-muted transition hover:bg-surface-muted hover:text-text disabled:pointer-events-none disabled:opacity-50"
                                :disabled="
                                    busyLineId === line.id || line.quantity <= 1
                                "
                                :aria-label="`Decrease quantity of ${line.label}`"
                                @click="changeQuantity(line, line.quantity - 1)"
                            >
                                <Minus class="size-3" />
                            </button>
                            <span
                                class="w-6 text-center text-sm tabular-nums"
                                aria-live="polite"
                            >
                                {{ line.quantity }}
                            </span>
                            <button
                                type="button"
                                class="inline-flex size-7 items-center justify-center rounded-md border border-border text-text-muted transition hover:bg-surface-muted hover:text-text disabled:pointer-events-none disabled:opacity-50"
                                :disabled="
                                    busyLineId === line.id ||
                                    (line.maxQuantity !== null &&
                                        line.quantity >= line.maxQuantity)
                                "
                                :aria-label="`Increase quantity of ${line.label}`"
                                @click="changeQuantity(line, line.quantity + 1)"
                            >
                                <Plus class="size-3" />
                            </button>
                        </div>
                        <span v-else class="text-sm text-text-muted">
                            Qty {{ line.quantity }}
                        </span>

                        <span
                            class="w-20 text-right text-sm font-medium text-text"
                        >
                            {{ line.lineGross }} {{ cart.currency }}
                        </span>

                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-md text-text-muted transition hover:bg-danger/10 hover:text-danger disabled:pointer-events-none disabled:opacity-50"
                            :disabled="busyLineId === line.id"
                            :aria-label="`Remove ${line.label} from your cart`"
                            @click="removeLine(line)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </li>
            </ul>

            <InputError :message="quantityError" />

            <div class="flex flex-col gap-1 border-t border-border pt-4">
                <div
                    class="flex items-center justify-between text-sm text-text-muted"
                >
                    <span>Subtotal</span>
                    <span>{{ cart.totalNet }} {{ cart.currency }}</span>
                </div>
                <div
                    class="flex items-center justify-between text-sm text-text-muted"
                >
                    <span>Tax</span>
                    <span>{{ cart.totalTax }} {{ cart.currency }}</span>
                </div>
                <div
                    class="flex items-center justify-between font-semibold text-text"
                >
                    <span>Total</span>
                    <span>{{ cart.totalGross }} {{ cart.currency }}</span>
                </div>
            </div>

            <div
                v-if="cart.accounts.length > 0"
                class="flex flex-col items-start gap-2"
            >
                <Button
                    v-for="account in cart.accounts"
                    :key="account.id"
                    :disabled="startingAccount === account.id"
                    @click="checkout(account.id)"
                >
                    <CreditCard class="size-4" />
                    Pay — {{ account.label }}
                </Button>

                <InputError :message="accountError" />
            </div>

            <p v-else class="text-sm text-text-muted">
                This organizer settles by bank transfer — payment details arrive
                with your confirmation.
            </p>
        </template>
    </div>
</template>
