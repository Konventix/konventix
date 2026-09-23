<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';

/**
 * The console's landing page composes its own counts with whatever modules
 * contribute to the `admin.dashboard` UI slot (ADR-0003 enforcement point 3),
 * the same way the per-event dashboard does: each contribution names its
 * component as a `<module>/<path>` string, resolved through
 * `lib/moduleComponents.ts`, so this page never imports a module's card.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

defineProps<{
    operator: { name: string | null };
    counts: { brands: number; pages: number };
    slots: SlotContribution[];
}>();
</script>

<template>
    <Head :title="$t('Admin')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="operator.name ?? $t('Operator console')"
            :description="$t('Everything the operator runs, across all brands')"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:max-w-xl">
            <div class="rounded-xl border border-border p-4">
                <div class="text-3xl font-semibold">{{ counts.brands }}</div>
                <div class="text-sm text-text-muted">{{ $t('Brands') }}</div>
            </div>
            <div class="rounded-xl border border-border p-4">
                <div class="text-3xl font-semibold">{{ counts.pages }}</div>
                <div class="text-sm text-text-muted">
                    {{ $t('Static pages (all brands)') }}
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <template
                v-for="slot in slots"
                :key="`${slot.module}-${slot.component}`"
            >
                <component
                    :is="resolveRegisteredComponent(slot.component)"
                    v-if="resolveRegisteredComponent(slot.component)"
                    v-bind="slot.props"
                />
            </template>
        </div>
    </div>
</template>
