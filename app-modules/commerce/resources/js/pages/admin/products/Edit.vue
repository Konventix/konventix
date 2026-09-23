<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { GripVertical, Plus, Trash2 } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { useId } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import MediaPicker from '@/components/admin/MediaPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminCapabilities } from '@/composables/useAdminCapabilities';
import { useDragReorder } from '@/composables/useDragReorder';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    destroy as destroyProduct,
    index as productsIndex,
    update as updateProduct,
} from '@/routes/admin/products';
import { priceDisplayLabel, taxClassLabel } from '../../../lib/labels';

type Product = Konventix.Commerce.Data.ProductData;
type Variant = Konventix.Commerce.Data.ProductVariantData;

const props = defineProps<{
    product: Product;
    taxClasses: Konventix.Commerce.Enums.TaxClass[];
    priceDisplays: Konventix.Commerce.Enums.PriceDisplay[];
    media: Konventix.Platform.Data.MediaItemData[];
    events: { id: string; name: string; brandId: string }[];
}>();
const { canManageMedia } = useAdminCapabilities();

const form = useForm<{
    name: string;
    slug: string;
    description: string | null;
    eventId: string | null;
    imageMediaId: string | null;
    taxClass: Konventix.Commerce.Enums.TaxClass;
    unitAmountNet: string;
    priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
    published: boolean;
    // Only read by the server when `variants` is empty — the capacity of
    // the single implicit variant a product with none still needs.
    stockCapacity: number;
    variants: Variant[];
}>({
    name: props.product.name,
    slug: props.product.slug,
    description: props.product.description,
    eventId: props.product.eventId,
    imageMediaId: props.product.imageMediaId,
    taxClass: props.product.taxClass,
    unitAmountNet: props.product.unitAmountNet,
    priceDisplay: props.product.priceDisplay,
    published: props.product.published,
    stockCapacity: props.product.variants[0]?.stockCapacity ?? 0,
    variants: props.product.variants.map((variant) => ({ ...variant })),
});

useUnsavedChanges(() => form.isDirty);

const reorderHintId = useId();

const {
    draggingIndex,
    onDragStart,
    onDragEnter,
    onDrop,
    onDragEnd,
    onHandleKeydown,
} = useDragReorder<Variant>({
    get: () => form.variants,
    set: (next) => (form.variants = next),
});

function addVariant(): void {
    form.variants = [
        ...form.variants,
        {
            id: null,
            label: null,
            sku: null,
            unitAmountNet: null,
            stockCapacity: 0,
            position: form.variants.length,
            remaining: null,
        },
    ];
}

function removeVariant(index: number): void {
    form.variants = form.variants.filter((_, i) => i !== index);
}

function errorFor(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[
        `variants.${index}.${field}`
    ];
}

function submit(): void {
    // `position` is the array's own order, not whatever the client happened
    // to carry on each row — finalized here so a drag or keyboard reorder
    // always lands correctly regardless of how the rows got here.
    form.transform((data) => ({
        ...data,
        variants: data.variants.map((variant, index) => ({
            id: variant.id,
            label: variant.label,
            sku: variant.sku,
            unitAmountNet: variant.unitAmountNet,
            stockCapacity: variant.stockCapacity,
            position: index,
        })),
    })).put(updateProduct(props.product.id).url, { preserveScroll: true });
}

function remove(): void {
    if (
        confirm(
            trans('Delete “:name”? This cannot be undone.', {
                name: props.product.name,
            }),
        )
    ) {
        router.delete(destroyProduct(props.product.id).url);
    }
}
</script>

<template>
    <Head :title="props.product.name" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.product.name"
            :description="
                $t('Product of :brand', { brand: props.product.brandName })
            "
        >
            <Button variant="ghost" @click="remove">
                <Trash2 class="size-4 text-danger" />
                {{ $t('Delete') }}
            </Button>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Product') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="product-name">{{ $t('Name') }}</Label>
                        <Input id="product-name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product-slug">{{ $t('Slug') }}</Label>
                        <Input id="product-slug" v-model="form.slug" />
                        <InputError :message="form.errors.slug" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="product-description">{{
                            $t('Description')
                        }}</Label>
                        <textarea
                            id="product-description"
                            v-model="form.description"
                            rows="3"
                            class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product-event">{{ $t('Event') }}</Label>
                        <select
                            id="product-event"
                            v-model="form.eventId"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option :value="null">{{ $t('No event') }}</option>
                            <option
                                v-for="event in props.events"
                                :key="event.id"
                                :value="event.id"
                            >
                                {{ event.name }}
                            </option>
                        </select>
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Optional — ties this product to one edition, for display only.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.eventId" />
                    </div>
                    <div class="grid gap-2">
                        <MediaPicker
                            v-model="form.imageMediaId"
                            :label="$t('Image')"
                            :media="props.media"
                            :brand-id="props.product.brandId"
                            :can-upload="canManageMedia"
                        />
                        <InputError :message="form.errors.imageMediaId" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product-tax-class">{{
                            $t('Tax class')
                        }}</Label>
                        <select
                            id="product-tax-class"
                            v-model="form.taxClass"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="taxClass in props.taxClasses"
                                :key="taxClass"
                                :value="taxClass"
                            >
                                {{ taxClassLabel(taxClass) }}
                            </option>
                        </select>
                        <InputError :message="form.errors.taxClass" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product-price-display">
                            {{ $t('Price display') }}
                        </Label>
                        <select
                            id="product-price-display"
                            v-model="form.priceDisplay"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="priceDisplay in props.priceDisplays"
                                :key="priceDisplay"
                                :value="priceDisplay"
                            >
                                {{ priceDisplayLabel(priceDisplay) }}
                            </option>
                        </select>
                        <InputError :message="form.errors.priceDisplay" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product-price">{{
                            $t('Price (net)')
                        }}</Label>
                        <Input
                            id="product-price"
                            v-model="form.unitAmountNet"
                            inputmode="decimal"
                        />
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'The default price; a variant may override it.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.unitAmountNet" />
                    </div>
                    <div v-if="form.variants.length === 0" class="grid gap-2">
                        <Label for="product-stock">{{
                            $t('Stock capacity')
                        }}</Label>
                        <Input
                            id="product-stock"
                            v-model.number="form.stockCapacity"
                            type="number"
                            min="0"
                        />
                        <p class="text-xs text-text-muted">
                            {{
                                $t(
                                    'No variants below — this capacity applies directly.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.stockCapacity" />
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="product-published"
                            :model-value="form.published"
                            @update:model-value="
                                (value: boolean | 'indeterminate') =>
                                    (form.published = value === true)
                            "
                        />
                        <Label for="product-published" class="font-normal">
                            {{ $t('Published') }}
                        </Label>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Variants') }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <p :id="reorderHintId" class="sr-only">
                        {{
                            $t(
                                'Drag a variant to reorder it, or focus its reorder handle and press the up or down arrow key.',
                            )
                        }}
                    </p>

                    <p
                        v-if="form.variants.length === 0"
                        class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
                    >
                        {{
                            $t(
                                'No separate variants — the stock capacity above applies directly to the product.',
                            )
                        }}
                    </p>

                    <div
                        v-for="(variant, index) in form.variants"
                        :key="index"
                        draggable="true"
                        class="rounded-lg border border-border p-4"
                        :class="draggingIndex === index && 'opacity-60'"
                        @dragstart="onDragStart(index, $event)"
                        @dragenter.prevent="onDragEnter(index)"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                        @dragend="onDragEnd"
                    >
                        <div class="mb-3 flex items-center gap-2">
                            <button
                                type="button"
                                class="cursor-grab rounded p-1 text-text-muted hover:text-text active:cursor-grabbing"
                                :aria-describedby="reorderHintId"
                                @keydown="onHandleKeydown(index, $event)"
                            >
                                <GripVertical class="size-4" />
                                <span class="sr-only">
                                    {{
                                        $t(
                                            'Reorder variant :variant, position :position of :total',
                                            {
                                                variant:
                                                    variant.label ??
                                                    String(index + 1),
                                                position: String(index + 1),
                                                total: String(
                                                    form.variants.length,
                                                ),
                                            },
                                        )
                                    }}
                                </span>
                            </button>
                            <span class="flex-1 text-sm font-medium">
                                {{
                                    variant.label ??
                                    $t('Variant :number', {
                                        number: String(index + 1),
                                    })
                                }}
                            </span>
                            <span
                                v-if="variant.id"
                                class="text-xs text-text-muted"
                            >
                                {{
                                    $t(':count remaining', {
                                        count: String(variant.remaining),
                                    })
                                }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="
                                    $t('Remove variant :number', {
                                        number: String(index + 1),
                                    })
                                "
                                @click="removeVariant(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="grid gap-2">
                                <Label :for="`variant-${index}-label`">
                                    {{ $t('Label') }}
                                </Label>
                                <Input
                                    :id="`variant-${index}-label`"
                                    v-model="variant.label"
                                />
                                <InputError
                                    :message="errorFor(index, 'label')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`variant-${index}-sku`">
                                    {{ $t('SKU') }}
                                </Label>
                                <Input
                                    :id="`variant-${index}-sku`"
                                    v-model="variant.sku"
                                />
                                <InputError :message="errorFor(index, 'sku')" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`variant-${index}-price`">
                                    {{ $t('Price override') }}
                                </Label>
                                <Input
                                    :id="`variant-${index}-price`"
                                    v-model="variant.unitAmountNet"
                                    inputmode="decimal"
                                    :placeholder="$t('Use product price')"
                                />
                                <InputError
                                    :message="errorFor(index, 'unitAmountNet')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`variant-${index}-capacity`">
                                    {{ $t('Stock capacity') }}
                                </Label>
                                <Input
                                    :id="`variant-${index}-capacity`"
                                    v-model.number="variant.stockCapacity"
                                    type="number"
                                    min="0"
                                />
                                <InputError
                                    :message="errorFor(index, 'stockCapacity')"
                                />
                            </div>
                        </div>
                    </div>

                    <InputError :message="form.errors.variants" />

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        @click="addVariant"
                    >
                        <Plus class="size-4" />
                        {{ $t('Add variant') }}
                    </Button>
                </CardContent>
            </Card>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ $t('Save product') }}
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                    >{{ $t('Unsaved changes') }}</span
                >
                <Link
                    :href="productsIndex().url"
                    class="text-sm text-text-muted hover:text-text"
                    >{{ $t('Back to products') }}</Link
                >
            </div>
        </form>
    </div>
</template>
