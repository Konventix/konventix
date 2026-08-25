<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DataTableFilterDef } from '@/composables/useDataTable';

/**
 * Toolbar idiom for operator-console data tables (ADR-0029): search, the
 * list's declared filters and the per-page selector, with a slot for
 * page-specific actions (e.g. a "Create" button). Ported concept from
 * LanCore's per-page table toolbar.
 */
withDefaults(
    defineProps<{
        search?: string;
        perPage?: number;
        perPageOptions?: number[];
        searchPlaceholder?: string;
        filters?: DataTableFilterDef[];
    }>(),
    {
        search: '',
        perPage: 15,
        perPageOptions: () => [10, 15, 25, 50, 100],
        searchPlaceholder: 'Search…',
        filters: () => [],
    },
);

const emit = defineEmits<{
    (e: 'update:search', value: string): void;
    (e: 'update:perPage', value: number): void;
    (e: 'update:filter', name: string, value: string | null): void;
}>();

/** Sentinel for the "All …" option; never leaves this component. */
const ALL = '__all__';
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative min-w-48 flex-1">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-muted"
            />
            <Input
                :model-value="search"
                :placeholder="searchPlaceholder"
                class="pl-8"
                @update:model-value="
                    (value) => emit('update:search', String(value))
                "
            />
        </div>

        <div
            v-for="filter in filters"
            :key="filter.name"
            class="flex items-center gap-2"
        >
            <label :for="`filter-${filter.name}`" class="sr-only">
                {{ filter.label }}
            </label>
            <Select
                :model-value="filter.value ?? ALL"
                @update:model-value="
                    (value) =>
                        emit(
                            'update:filter',
                            filter.name,
                            value === ALL
                                ? (filter.clearValue ?? null)
                                : String(value),
                        )
                "
            >
                <SelectTrigger :id="`filter-${filter.name}`" class="w-44">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">
                        All {{ filter.label.toLowerCase() }}
                    </SelectItem>
                    <SelectItem
                        v-for="option in filter.options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <label for="per-page" class="sr-only">Rows per page</label>
        <Select
            :model-value="String(perPage)"
            @update:model-value="
                (value) => emit('update:perPage', Number(value))
            "
        >
            <SelectTrigger id="per-page" class="w-28">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="n in perPageOptions"
                    :key="n"
                    :value="String(n)"
                >
                    {{ n }} / page
                </SelectItem>
            </SelectContent>
        </Select>

        <slot name="actions" />
    </div>
</template>
