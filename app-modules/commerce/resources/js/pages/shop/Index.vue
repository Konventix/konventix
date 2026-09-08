<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { displayUnitAmount, taxNote } from '@/lib/purchaseTexts';
import { show as showProduct } from '@/routes/commerce/shop';

/**
 * The brand's public shop listing (ADR-0047, Task 8) — every published
 * product, brand-scoped by `BelongsToBrand` like any other brand host page.
 * The variant `<select>` and quantity picker live one level down, on
 * `Show.vue`; this screen only ever links there.
 *
 * Goods are not all a brand sells (#240): Registration contributes its own
 * types through the `shop.index` slot and this page renders whatever comes
 * back without knowing what it is, the same way the event landing page
 * renders Commerce's card. The empty-shelf line is therefore about the
 * goods alone: a brand with no merchandise and a dozen ticket types is not
 * an empty shop.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type Product = Konventix.Commerce.Data.ShopProductData;

const props = defineProps<{
    products: Product[];
    slots: SlotContribution[];
}>();
</script>

<template>
    <Head title="Shop" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6 text-text lg:p-8"
    >
        <Heading
            as="h1"
            title="Shop"
            description="Merchandise for this brand"
        />

        <!--
            #240: "nothing here" is now only about the goods — a brand with
            no merchandise and a dozen ticket types is not an empty shop, and
            the contributions below say so themselves.
        -->
        <p v-if="props.products.length === 0" class="text-sm text-text-muted">
            No merchandise yet.
        </p>

        <ul v-if="props.products.length > 0" class="grid gap-4 sm:grid-cols-2">
            <li v-for="product in props.products" :key="product.id">
                <Link
                    :href="showProduct(product.slug).url"
                    class="flex h-full flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <img
                        v-if="product.imageUrl"
                        :src="product.imageUrl"
                        :alt="product.name"
                        class="aspect-square w-full rounded-lg object-cover"
                    />

                    <div class="flex items-start justify-between gap-2">
                        <span class="font-medium text-text">
                            {{ product.name }}
                        </span>
                        <span
                            v-if="product.soldOut"
                            class="shrink-0 rounded-full bg-danger/15 px-2 py-0.5 text-xs font-medium text-danger"
                        >
                            Sold out
                        </span>
                    </div>

                    <p
                        v-if="product.description"
                        class="line-clamp-2 text-sm text-text-muted"
                    >
                        {{ product.description }}
                    </p>

                    <span class="mt-auto text-sm font-medium text-text">
                        {{ displayUnitAmount(product) }} €
                        <span class="font-normal text-text-muted">
                            {{ taxNote(product.priceDisplay) }}
                        </span>
                    </span>
                </Link>
            </li>
        </ul>

        <template v-for="slot in props.slots" :key="slot.component">
            <component
                :is="resolveRegisteredComponent(slot.component)"
                v-if="resolveRegisteredComponent(slot.component)"
                v-bind="slot.props"
            />
        </template>
    </div>
</template>
