<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { show as showProduct } from '@/routes/commerce/shop';

/**
 * The brand's public shop listing (ADR-0047, Task 8) — every published
 * product, brand-scoped by `BelongsToBrand` like any other brand host page.
 * The variant `<select>` and quantity picker live one level down, on
 * `Show.vue`; this screen only ever links there.
 */
type Product = Konventix.Commerce.Data.ShopProductData;

const props = defineProps<{
    products: Product[];
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

        <p v-if="props.products.length === 0" class="text-sm text-text-muted">
            Nothing on the shelf yet.
        </p>

        <ul v-else class="grid gap-4 sm:grid-cols-2">
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
                        {{ product.unitAmountNet }} €
                        <span
                            v-if="product.priceDisplay === 'net'"
                            class="font-normal text-text-muted"
                        >
                            excl. VAT
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
