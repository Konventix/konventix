<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { displayUnitAmount, taxNote } from '@/lib/purchaseTexts';
import { login } from '@/routes';
import { store as storeCartLine } from '@/routes/commerce/cart/lines';

/**
 * One shop product (ADR-0047, Task 8/11). The buy button posts straight to
 * `commerce.cart.lines.store` — a single immediate action, not a draft form
 * (ADR-0038's dirty guard does not apply, the same reasoning `pay/Show.vue`
 * already applies to its own account buttons). A guest sees a sign-in link
 * in its place: `/login` sends them back here once they are, the same
 * round trip every other auth-gated action on a brand host makes.
 */
type Variant = Konventix.Commerce.Data.ShopVariantData;
type Product = Konventix.Commerce.Data.ShopProductData;

const props = defineProps<{
    product: Product;
    /** The brand's currency (#234) — never a hardcoded symbol. */
    currency: string;
}>();

const page = usePage();
const isGuest = computed(() => page.props.auth.user === null);
const quantityError = computed(() => page.props.errors.quantity);
const adding = ref(false);

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

// PAngV (#3): what a variant costs is shown in the same net-or-gross terms
// the product is priced in, and both amounts come from the server.
function variantAmount(variant: Variant): string {
    return displayUnitAmount({
        unitAmountNet: variant.unitAmountNet,
        unitAmountGross: variant.unitAmountGross,
        priceDisplay: props.product.priceDisplay,
    });
}

function addToCart(): void {
    if (selectedVariantId.value === null) {
        return;
    }

    adding.value = true;
    router.post(
        storeCartLine().url,
        { variantId: selectedVariantId.value, quantity: quantity.value },
        { preserveScroll: true, onFinish: () => (adding.value = false) },
    );
}
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
                {{ displayUnitAmount(props.product) }} {{ props.currency }}
                <span class="text-sm font-normal text-text-muted">
                    {{
                        taxNote(
                            props.product.priceDisplay,
                            props.product.smallBusinessScheme,
                        )
                    }}
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
                            — {{ variantAmount(variant) }}
                            {{ props.currency }}
                            {{
                                taxNote(
                                    props.product.priceDisplay,
                                    props.product.smallBusinessScheme,
                                )
                            }}
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

            <Button v-if="isGuest" as-child class="w-fit">
                <Link :href="login()">Sign in to buy</Link>
            </Button>
            <template v-else>
                <Button
                    type="button"
                    :disabled="props.product.soldOut || adding"
                    class="w-fit"
                    @click="addToCart"
                >
                    Add to cart
                </Button>
                <InputError :message="quantityError" />
            </template>
        </div>
    </div>
</template>
