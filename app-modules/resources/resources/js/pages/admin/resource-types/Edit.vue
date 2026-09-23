<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    index as typesIndex,
    update as updateType,
} from '@/routes/admin/resource-types';
import { capacityModeLabel } from '../../../components/plan/labels';

/**
 * One category (design §4.1): identity, hold minutes for clocked holds,
 * and what the picker and the editor need — colour token, lucide symbol,
 * sort order and the default place shape the editor uses when it creates
 * places of this category.
 *
 * `places` is the bookable count (published, unblocked places, #282), the
 * same number the list shows — never a raw row count, because it is what
 * the operator reads to size the category's Registration quota. Event and
 * mode freeze once the category has *any* place row, published or not:
 * `UpdateResourceType::guardFrozenAxes()` is the authority on that, so a
 * category whose only places are still draft can show zero bookable
 * places here and still have the server refuse an axis change — the form
 * disables what it can from the hint it has, the server has the last word.
 */
type Props = {
    type: Konventix.Resources.Data.ResourceTypeData & {
        id: string;
        brandName: string;
    };
    events: { id: string; name: string; brandId: string }[];
    options: { mode: string[]; token: string[] };
    places: number;
};

const props = defineProps<Props>();

const form = useForm({
    eventId: props.type.eventId,
    name: props.type.name,
    slug: props.type.slug,
    mode: props.type.mode,
    holdMinutes: props.type.holdMinutes,
    description: props.type.description ?? '',
    token: props.type.token,
    symbol: props.type.symbol ?? '',
    sortOrder: props.type.sortOrder,
    // Not edited directly — the width/height inputs below assemble this at
    // submit time (`transform()`). Kept as a form field only so
    // `form.errors.defaultShape` type-checks against a server validation
    // error on this key.
    defaultShape: props.type.defaultShape,
});

useUnsavedChanges(() => form.isDirty);

const shapeWidth = ref<number | null>(props.type.defaultShape?.w ?? null);
const shapeHeight = ref<number | null>(props.type.defaultShape?.h ?? null);

const frozen = props.places > 0;

function submit(): void {
    form.transform((data) => ({
        ...data,
        description: data.description === '' ? null : data.description,
        symbol: data.symbol === '' ? null : data.symbol,
        defaultShape:
            shapeWidth.value && shapeHeight.value
                ? { type: 'rect', w: shapeWidth.value, h: shapeHeight.value }
                : null,
    })).put(updateType(props.type.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t(':name — Category', { name: props.type.name })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.type.name"
            :description="props.type.brandName"
        >
            <span
                v-if="frozen"
                class="inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning"
            >
                {{
                    $t(':count bookable places — event and mode frozen', {
                        count: String(props.places),
                    })
                }}
            </span>
            <Button variant="outline" as-child>
                <Link :href="typesIndex().url">
                    <ArrowLeft class="size-4" />
                    {{ $t('All categories') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Identity') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category-name">{{ $t('Name') }}</Label>
                            <Input id="category-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-slug">{{ $t('Slug') }}</Label>
                            <Input id="category-slug" v-model="form.slug" />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-event">{{
                                $t('Event')
                            }}</Label>
                            <select
                                id="category-event"
                                v-model="form.eventId"
                                :disabled="frozen"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                            >
                                <option
                                    v-for="event in props.events"
                                    :key="event.id"
                                    :value="event.id"
                                >
                                    {{ event.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.eventId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-mode">{{ $t('Mode') }}</Label>
                            <select
                                id="category-mode"
                                v-model="form.mode"
                                :disabled="frozen"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                            >
                                <option
                                    v-for="value in props.options.mode"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ capacityModeLabel(value) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.mode" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-hold">{{
                                $t('Hold minutes')
                            }}</Label>
                            <Input
                                id="category-hold"
                                v-model.number="form.holdMinutes"
                                type="number"
                                min="1"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'For clocked holds only; a picked seat is confirmed in the same transaction as its hold.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.holdMinutes" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="category-description">
                                {{ $t('Description') }}
                            </Label>
                            <textarea
                                id="category-description"
                                v-model="form.description"
                                rows="3"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Shown to the registrant in the seat picker.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.description" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Appearance') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="category-token">{{
                                $t('Colour token')
                            }}</Label>
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-4 shrink-0 rounded-full border border-border"
                                    :style="{
                                        backgroundColor: `var(--${form.token})`,
                                    }"
                                    aria-hidden="true"
                                />
                                <select
                                    id="category-token"
                                    v-model="form.token"
                                    class="h-9 flex-1 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="value in props.options.token"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                            </div>
                            <InputError :message="form.errors.token" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-symbol">{{
                                $t('Symbol')
                            }}</Label>
                            <Input
                                id="category-symbol"
                                v-model="form.symbol"
                                :placeholder="
                                    $t('lucide icon key, e.g. armchair')
                                "
                            />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Mandatory for the picker: a state is never told by colour alone.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.symbol" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category-order">{{
                                $t('Sort order')
                            }}</Label>
                            <Input
                                id="category-order"
                                v-model.number="form.sortOrder"
                                type="number"
                                min="0"
                            />
                            <InputError :message="form.errors.sortOrder" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Default place size') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    "Millimetres. The editor uses this rectangle for new places of this category; leave both empty for the editor's own default.",
                                )
                            }}
                        </p>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="shape-w">{{ $t('Width') }}</Label>
                                <Input
                                    id="shape-w"
                                    v-model.number="shapeWidth"
                                    type="number"
                                    min="1"
                                    placeholder="800"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="shape-h">{{ $t('Height') }}</Label>
                                <Input
                                    id="shape-h"
                                    v-model.number="shapeHeight"
                                    type="number"
                                    min="1"
                                    placeholder="800"
                                />
                            </div>
                        </div>
                        <InputError :message="form.errors.defaultShape" />
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </div>
        </form>
    </div>
</template>
