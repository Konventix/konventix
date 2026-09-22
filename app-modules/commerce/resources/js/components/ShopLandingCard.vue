<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { displayUnitAmount, taxNote } from '@/lib/purchaseTexts';
import { show as showProduct } from '@/routes/commerce/shop';

/**
 * Commerce's contribution to Event's `event.landing` slot (ADR-0047,
 * Task 8): what a visitor can buy for this edition, resolved server-side
 * the same way Registration's `EventOffers` resolves what they can
 * register for — so the call to action is in the first paint rather than
 * a fetch behind it.
 *
 * `commerce:shop` gates this slot entry itself (see
 * `CommerceServiceProvider::registerSlots()`), so by the time this
 * component renders the feature is already on; it renders nothing only
 * when the brand simply has no published product for this event.
 */
type Product = Konventix.Commerce.Data.ShopProductData;

const props = defineProps<{
    products: Product[];
    /**
     * The brand's currency (#234). Optional for the same reason, and the
     * card simply prints the amount without one if an older payload
     * reaches it — never a symbol nobody chose.
     */
    currency?: string;
}>();
</script>

<template>
    <section
        v-if="props.products.length > 0"
        class="flex flex-col gap-3"
        aria-labelledby="event-shop"
    >
        <h2 id="event-shop" class="text-lg font-medium">Shop</h2>

        <ul class="flex flex-col gap-3">
            <li v-for="product in props.products" :key="product.id">
                <Link
                    :href="showProduct(product.slug).url"
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="font-medium text-text">
                            {{ product.name }}
                        </span>
                        <span
                            v-if="product.soldOut"
                            class="text-sm text-danger"
                        >
                            Sold out
                        </span>
                    </span>

                    <span
                        class="shrink-0 text-sm whitespace-nowrap text-text-muted"
                    >
                        {{ displayUnitAmount(product) }} {{ props.currency }}
                        {{
                            taxNote(
                                product.priceDisplay,
                                product.smallBusinessScheme,
                            )
                        }}
                    </span>

                    <ArrowRight
                        class="size-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ul>
    </section>
</template>
