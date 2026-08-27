<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';

/**
 * One shop product (ADR-0047, Task 8). The variant `<select>` and quantity
 * `<input>` are wired up for real, but there is nowhere for them to submit
 * to yet — `commerce.cart.add` is Task 11's route. Until then the buy
 * button renders disabled, and a guest sees a sign-in link in its place:
 * `/login` sends them back here once they are, the same round trip every
 * other auth-gated action on a brand host makes.
 */
type Variant = Konventix.Commerce.Data.ShopVariantData;
type Product = Konventix.Commerce.Data.ShopProductData;

const props = defineProps<{
    product: Product;
}>();

const page = usePage();
const isGuest = computed(() => page.props.auth.user === null);

const firstAvailable = props.product.variants.find(
    (variant: Variant) => variant.remaining > 0,
);
const selectedVariantId = ref<string | null>(
    (firstAvailable ?? props.product.variants[0])?.id ?? null,
);

const selectedVariant = computed<Variant | null>(
    () =>
        props.product.variants.find(
            (variant) => variant.id === selectedVariantId.value,
        ) ?? null,
);

const quantity = ref(1);
</script>

<template>
    <Head :title="props.product.name" />

    <div
        class="mx-auto flex w-full max-w-2xl flex-col gap-8 p-6 text-text lg:p-8"
    >
        <img
            v-if="props.product.imageUrl"
            :src="props.product.imageUrl"
            :alt="props.product.name"
            class="aspect-video w-full rounded-xl object-cover"
        />

        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-3">
                <Heading as="h1" :title="props.product.name" />
                <span
                    v-if="props.product.soldOut"
                    class="rounded-full bg-danger/15 px-2 py-0.5 text-xs font-medium text-danger"
                >
                    Sold out
                </span>
            </div>

            <p
                v-if="props.product.description"
                class="max-w-prose text-text-muted"
            >
                {{ props.product.description }}
            </p>

            <p class="text-lg font-medium text-text">
                {{ props.product.unitAmountNet }} €
                <span
                    v-if="props.product.priceDisplay === 'net'"
                    class="text-sm font-normal text-text-muted"
                >
                    excl. VAT
                </span>
            </p>
        </div>

        <div
            class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-4"
        >
            <div class="grid gap-2">
                <Label for="shop-variant">Variant</Label>
                <select
                    id="shop-variant"
                    v-model="selectedVariantId"
                    :disabled="props.product.soldOut"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <option
                        v-for="variant in props.product.variants"
                        :key="variant.id"
                        :value="variant.id"
                        :disabled="variant.remaining === 0"
                    >
                        {{ variant.label ?? props.product.name }}
                        <template v-if="variant.remaining === 0">
                            — sold out
                        </template>
                        <template v-else>
                            — {{ variant.unitAmountNet }} €
                        </template>
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="shop-quantity">Quantity</Label>
                <Input
                    id="shop-quantity"
                    v-model.number="quantity"
                    type="number"
                    min="1"
                    :max="selectedVariant?.remaining ?? 1"
                    :disabled="props.product.soldOut"
                    class="w-24"
                />
            </div>

            <!-- TODO(tranche-3 T11): wire commerce.cart.add -->
            <Button v-if="isGuest" as-child class="w-fit">
                <Link :href="login()">Sign in to buy</Link>
            </Button>
            <Button
                v-else
                type="button"
                disabled
                aria-disabled="true"
                title="Cart arrives with a later task"
                class="w-fit"
            >
                Add to cart
            </Button>
        </div>
    </div>
</template>
