<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * Resources' contribution to `registration.type-editor.seating-fields`
 * (design §7.1, §9.3): which categories a seating grant may choose from,
 * and whether the registrant picks or the operator assigns. The
 * categories arrive as closure props resolved for the type's event; the
 * grant itself is Registration's and travels as `modelValue`, edited by
 * emitting a copy — this component never mutates what it was handed.
 *
 * The quantity input in the grant row above is the maximum number of
 * places (§7.1); it is not repeated here.
 *
 * `bookablePlaces` (#282, design §12b) rides alongside each category —
 * not on `CategoryData` itself, which the picker also uses and has no
 * business showing an operator-only figure to a registrant. It is the
 * same count the category console labels "Bookable places": published,
 * not blocked, not deleted, resolved by `ResourcesServiceProvider`'s slot
 * closure from this module's own tables. It sits next to the quota
 * checkboxes' consumed/capacity figures on the same screen so an operator
 * sizing a category's Registration quota (the ceiling is now a hand-typed
 * quota capacity, not a computed one) has both numbers in view at once.
 */
type Grant = Konventix.Registration.Data.GrantData;
type Category = Konventix.Resources.Data.CategoryData & {
    bookablePlaces: number;
};

const props = withDefaults(
    defineProps<{
        modelValue: Grant;
        categories: Category[];
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: Grant): void;
}>();

const selected = computed(() => props.modelValue.resourceTypeIds ?? []);

function toggle(id: string, checked: boolean): void {
    emit('update:modelValue', {
        ...props.modelValue,
        resourceTypeIds: checked
            ? [...new Set([...selected.value, id])]
            : selected.value.filter((existing) => existing !== id),
    });
}

function setMode(mode: string): void {
    emit('update:modelValue', {
        ...props.modelValue,
        seatingMode: mode as Grant['seatingMode'],
    });
}
</script>

<template>
    <div class="grid gap-4 rounded-md bg-subtle/40 p-3 sm:grid-cols-2">
        <fieldset class="grid gap-2">
            <legend class="text-sm font-medium">Seat categories</legend>
            <p
                v-if="props.categories.length === 0"
                class="text-sm text-text-muted"
            >
                This event has no categories yet — create them under Categories
                first.
            </p>
            <div
                v-for="category in props.categories"
                :key="category.id"
                class="flex items-center gap-2"
            >
                <Checkbox
                    :id="`seating-category-${category.id}`"
                    :model-value="selected.includes(category.id)"
                    :disabled="props.disabled"
                    @update:model-value="
                        (value: boolean | 'indeterminate') =>
                            toggle(category.id, value === true)
                    "
                />
                <Label
                    :for="`seating-category-${category.id}`"
                    class="flex flex-1 items-center gap-2"
                >
                    <span
                        class="size-3 shrink-0 rounded-full border border-border"
                        :style="{ backgroundColor: `var(--${category.token})` }"
                        aria-hidden="true"
                    />
                    <span>{{ category.name }}</span>
                    <span
                        v-if="category.symbol"
                        class="text-xs text-text-muted"
                    >
                        {{ category.symbol }}
                    </span>
                    <span class="ml-auto text-xs text-text-muted">
                        {{ category.bookablePlaces }} bookable
                    </span>
                </Label>
            </div>
        </fieldset>

        <div class="grid content-start gap-2">
            <Label :for="`seating-mode-${props.modelValue.role || 'grant'}`">
                Seating mode
            </Label>
            <select
                :id="`seating-mode-${props.modelValue.role || 'grant'}`"
                :value="props.modelValue.seatingMode ?? 'pick'"
                :disabled="props.disabled"
                class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                @change="setMode(($event.target as HTMLSelectElement).value)"
            >
                <option value="pick">pick — the registrant chooses</option>
                <option value="assign">assign — the operator seats them</option>
            </select>
            <p class="text-xs text-text-muted">
                The quantity in this row is the maximum number of places.
                Categories freeze with the other terms once anyone has
                registered.
            </p>
        </div>
    </div>
</template>
