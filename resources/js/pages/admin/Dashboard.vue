<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';

defineProps<{
    operator: { name: string | null };
    counts: { brands: number; pages: number };
}>();
</script>

<template>
    <Head title="Admin" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="operator.name ?? 'Operator console'"
            description="Everything the operator runs, across all brands"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:max-w-xl">
            <div class="rounded-xl border border-border p-4">
                <div class="text-3xl font-semibold">{{ counts.brands }}</div>
                <div class="text-sm text-text-muted">Brands</div>
            </div>
            <div class="rounded-xl border border-border p-4">
                <div class="text-3xl font-semibold">{{ counts.pages }}</div>
                <div class="text-sm text-text-muted">
                    Static pages (all brands)
                </div>
            </div>
        </div>
    </div>
</template>
