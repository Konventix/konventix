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

/**
 * Toolbar idiom for operator-console data tables (ADR-0029): search + per-page
 * selector, with a slot for page-specific actions (e.g. a "Create" button).
 * Ported concept from LanCore's per-page table toolbar.
 */
withDefaults(
    defineProps<{
        search?: string;
        perPage?: number;
        perPageOptions?: number[];
        searchPlaceholder?: string;
    }>(),
    {
        search: '',
        perPage: 15,
        perPageOptions: () => [10, 15, 25, 50, 100],
        searchPlaceholder: 'Search…',
    },
);

const emit = defineEmits<{
    (e: 'update:search', value: string): void;
    (e: 'update:perPage', value: number): void;
}>();
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

        <Select
            :model-value="String(perPage)"
            @update:model-value="
                (value) => emit('update:perPage', Number(value))
            "
        >
            <SelectTrigger class="w-28">
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
