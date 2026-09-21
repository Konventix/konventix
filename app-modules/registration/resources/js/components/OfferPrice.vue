<script setup lang="ts">
import { computed } from 'vue';
import { displayUnitAmount, taxNote } from '@/lib/purchaseTexts';

/**
 * What one offer costs, in words, wherever this module prints a price
 * (#234, D-COM-20).
 *
 * It exists because the same three lines were written five times — twice in
 * `EventOffers.vue` alone, once per branch — and drifted: the landing page
 * printed a bare net amount with no tax note at all while the Commerce card
 * three inches beside it printed the PAngV end price with one. A price that
 * reads differently on two halves of one page is exactly what #3 was filed
 * about, so there is one implementation of it now and the surfaces only
 * choose where to put it.
 *
 * ADR-0006 is unchanged and deliberate: the net-versus-gross *display*
 * hangs on the registration type. A `gross` type (the B2C default) prints
 * the end price and says "incl. VAT"; a type the organizer deliberately set
 * to `net` prints the net amount and says "excl. VAT", because that is the
 * honest amount for a B2B audience. `displayUnitAmount()` picks and
 * `taxNote()` labels — both from `@/lib/purchaseTexts`, the one place the
 * purchase path's legally required wording lives.
 *
 * `endPrice` null is the fail-soft case of Platform's
 * `Pricing\EndPriceCalculator`: nothing bound, or a brand with no
 * settlement identity yet. The declared net amount is then printed with
 * "excl. VAT" — never a gross claim nobody computed — and without a
 * currency, because the currency travels with the end price and only
 * Commerce knows it. This is what every one of these cards did before #234.
 *
 * The browser does no arithmetic on money (#3): both amounts arrive
 * computed from the server.
 */
const props = defineProps<{
    price: Konventix.Registration.Data.PriceDeclarationData | null;
    endPrice: Konventix.Platform.Data.EndPriceData | null;
}>();

const amount = computed<string | null>(() => {
    if (props.price === null) {
        return null;
    }

    if (props.endPrice === null) {
        return props.price.unitAmountNet;
    }

    return displayUnitAmount({
        unitAmountNet: props.endPrice.unitAmountNet,
        unitAmountGross: props.endPrice.unitAmountGross,
        priceDisplay: props.price.display,
    });
});

const note = computed<string>(() =>
    props.price === null || props.endPrice === null
        ? taxNote('net')
        : taxNote(props.price.display, props.endPrice.includesTax),
);
</script>

<template>
    <span>
        <template v-if="amount !== null">
            {{ amount }}
            <template v-if="props.endPrice">{{
                props.endPrice.currency
            }}</template>
            <span class="font-normal text-text-muted">{{ note }}</span>
        </template>
        <template v-else>Free</template>
    </span>
</template>
