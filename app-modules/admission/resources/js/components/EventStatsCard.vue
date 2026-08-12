<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import StatCard from '@/components/admin/StatCard.vue';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Admission's contribution to the Event admin dashboard's
 * `event.admin.dashboard` slot, registered by
 * `AdmissionServiceProvider::registerSlots()`. Fetches its own numbers from
 * Admission's own admin endpoint (`GET /admin/events/{event}/admission-stats`)
 * — the Event dashboard controller never queries Admission's models (no
 * cross-module Eloquent imports).
 */
type AdmissionStats = {
    counts: Record<string, number>;
    total: number;
};

const props = defineProps<{
    eventId: string;
    refreshToken?: number;
}>();

const stats = ref<AdmissionStats | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;

    try {
        const response = await fetch(
            `/admin/events/${props.eventId}/admission-stats`,
            { headers: { Accept: 'application/json' } },
        );

        stats.value = response.ok ? await response.json() : null;
    } catch {
        stats.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(
    () => props.refreshToken,
    () => void load(),
);
</script>

<template>
    <div class="col-span-full flex flex-col gap-4 lg:col-span-1">
        <h2 class="text-lg font-semibold text-text">Admissions</h2>

        <div v-if="loading" class="grid grid-cols-2 gap-4">
            <Skeleton v-for="i in 2" :key="i" class="h-24 w-full" />
        </div>

        <template v-else-if="stats">
            <div class="grid grid-cols-2 gap-4">
                <StatCard
                    title="Issued"
                    :value="stats.counts.issued ?? 0"
                    :subtext="`of ${stats.total} total`"
                />
                <StatCard
                    title="Assigned"
                    :value="stats.counts.assigned ?? 0"
                />
            </div>
        </template>

        <p v-else class="text-sm text-danger">
            Could not load admission stats.
        </p>
    </div>
</template>
