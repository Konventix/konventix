<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CreditCard, Minus, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fulfilmentNote, taxNote } from '@/lib/purchaseTexts';
import { choose as chooseCartAccount } from '@/routes/commerce/cart';
import {
    destroy as destroyLine,
    update as updateLine,
} from '@/routes/commerce/cart/lines';
import { index as shopIndex } from '@/routes/commerce/shop';

/**
 * The person-owned cart (ADR-0047, Task 11): line list with quantity
 * controls and remove, a tax preview of the totals checkout recomputes for
 * real, and one button per offered settlement account — the same pattern
 * `pay/Show.vue` already renders for the direct registration checkout.
 *
 * No dirty guard (ADR-0038): every change here — quantity, remove, add —
 * is its own immediate request with no draft state a navigation could lose.
 *
 * Since ADR-0066 this is **step one of two** (D-CPL-07): the lines, the
 * billing address where the total needs one, and the provider choice — and
 * nothing legal at all. Which texts apply may depend on the provider, so the
 * provider is chosen here and the texts are listed on `cart/Confirm.vue`
 * with the single accept control. Unlike the pay page this step is never
 * skipped: step one here *is* the cart, which the payer has to see whatever
 * the provider situation is.
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

/**
 * §33 UStDV vs §14 Abs. 4 UStG (#4): `cart.billingAddressRequired` is the
 * same figure `PlaceOrderFromCart::guardBillingAddress()` checks at
 * submit time, so the address fields appear before the payer ever hits a
 * 422 — but the server re-checks against the cart it actually placed, not
 * these fields' mere presence, since the total can still move between
 * this render and the submit. Country defaults to DE: Konventix's release
 * scope is the German market (ADR-0030), and a domestic payer should not
 * have to type it.
 */
const billingAddressLine = ref('');
const billingPostalCode = ref('');
const billingCity = ref('');
const billingCountryCode = ref('DE');

const billingAddressLineError = computed(
    () => page.props.errors.billingAddressLine,
);
const billingPostalCodeError = computed(
    () => page.props.errors.billingPostalCode,
);
const billingCityError = computed(() => page.props.errors.billingCity);
const billingCountryCodeError = computed(
    () => page.props.errors.billingCountryCode,
);

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

function choose(accountId: string): void {
    startingAccount.value = accountId;
    router.post(
        chooseCartAccount().url,
        {
            account: accountId,
            // Empty strings read back as null, not as "present but blank" —
            // `PlaceOrderFromCart::guardBillingAddress()` and
            // `BillingPartyData::hasPostalAddress()` both treat any field
            // still empty as missing (#4).
            billingAddressLine: billingAddressLine.value || null,
            billingPostalCode: billingPostalCode.value || null,
            billingCity: billingCity.value || null,
            billingCountryCode: billingCountryCode.value || null,
        },
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
            <Link :href="shopIndex().url" class="underline underline-offset-4">
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
                        <Link
                            v-if="line.url"
                            :href="line.url"
                            class="font-medium text-text underline-offset-4 hover:underline"
                        >
                            {{ line.label }}
                        </Link>
                        <span v-else class="font-medium text-text">{{
                            line.label
                        }}</span>
                        <span class="text-sm text-text-muted">
                            {{ line.unitAmountGross }} {{ cart.currency }} each
                            {{ taxNote('gross') }}
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
                    <span>
                        {{ cart.totalGross }} {{ cart.currency }}
                        <span class="font-normal text-text-muted">
                            {{ taxNote('gross') }}
                        </span>
                    </span>
                </div>
            </div>

            <!--
                #4: German VAT law (§14 Abs. 4 UStG) needs a postal address
                on the invoice above the §33 UStDV small-amount ceiling —
                asked for here, before the order is placed, rather than only
                after checkout refuses it (`billingAddressRequired` mirrors
                `PlaceOrderFromCart::guardBillingAddress()`'s own check).
            -->
            <div
                v-if="cart.billingAddressRequired"
                class="flex flex-col gap-4 border-t border-border pt-4"
            >
                <p class="text-sm text-text-muted">
                    Orders over 250 € need a billing address on the invoice.
                </p>
                <div class="grid gap-2">
                    <Label for="billingAddressLine">Street and number</Label>
                    <Input
                        id="billingAddressLine"
                        v-model="billingAddressLine"
                        autocomplete="street-address"
                        :aria-invalid="
                            billingAddressLineError ? true : undefined
                        "
                        :aria-describedby="
                            billingAddressLineError
                                ? 'billingAddressLine-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="billingAddressLine-error"
                        :message="billingAddressLineError"
                    />
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div class="grid gap-2">
                        <Label for="billingPostalCode">Postal code</Label>
                        <Input
                            id="billingPostalCode"
                            v-model="billingPostalCode"
                            autocomplete="postal-code"
                            :aria-invalid="
                                billingPostalCodeError ? true : undefined
                            "
                            :aria-describedby="
                                billingPostalCodeError
                                    ? 'billingPostalCode-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="billingPostalCode-error"
                            :message="billingPostalCodeError"
                        />
                    </div>
                    <div class="col-span-2 grid gap-2">
                        <Label for="billingCity">City</Label>
                        <Input
                            id="billingCity"
                            v-model="billingCity"
                            autocomplete="address-level2"
                            :aria-invalid="billingCityError ? true : undefined"
                            :aria-describedby="
                                billingCityError
                                    ? 'billingCity-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="billingCity-error"
                            :message="billingCityError"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="billingCountryCode">Country code</Label>
                    <Input
                        id="billingCountryCode"
                        v-model="billingCountryCode"
                        autocomplete="country"
                        maxlength="2"
                        class="max-w-24 uppercase"
                        :aria-invalid="
                            billingCountryCodeError ? true : undefined
                        "
                        :aria-describedby="
                            billingCountryCodeError
                                ? 'billingCountryCode-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="billingCountryCode-error"
                        :message="billingCountryCodeError"
                    />
                </div>
            </div>

            <!--
                #75: how and when what was bought arrives has to be stated
                before the order is placed, not only in the confirmation.
            -->
            <p class="text-sm text-text-muted">
                {{ fulfilmentNote(cart.accounts.length > 0) }}
            </p>

            <div
                v-if="cart.accounts.length > 0"
                class="flex flex-col items-start gap-2"
            >
                <Button
                    v-for="account in cart.accounts"
                    :key="account.id"
                    :disabled="startingAccount === account.id"
                    @click="choose(account.id)"
                >
                    <CreditCard class="size-4" />
                    Continue — {{ account.label }}
                </Button>

                <p role="status" class="text-sm text-text-muted">
                    You will see what you are agreeing to before the order is
                    placed.
                </p>

                <InputError :message="accountError" />
            </div>
        </template>
    </div>
</template>
