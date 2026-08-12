<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import StatCard from '@/components/admin/events/StatCard.vue';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Registration's contribution to the Event admin dashboard's
 * `event.admin.dashboard` slot, registered by
 * `RegistrationServiceProvider::registerSlots()`. Fetches its own numbers
 * from Registration's own admin endpoint (`GET
 * /admin/events/{event}/registration-stats`) — the Event dashboard
 * controller never queries Registration's models (no cross-module Eloquent
 * imports).
 */
type RegistrationTypeRow = {
    id: string;
    name: string;
    sold: number;
    capacity: number | null;
    remaining: number | null;
};

type RegistrationStats = {
    counts: Record<string, number>;
    types: RegistrationTypeRow[];
};

const props = defineProps<{
    eventId: string;
    refreshToken?: number;
}>();

const stats = ref<RegistrationStats | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;

    try {
        const response = await fetch(
            `/admin/events/${props.eventId}/registration-stats`,
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
    <div class="col-span-full flex flex-col gap-4 lg:col-span-2">
        <h2 class="text-lg font-semibold text-text">Registrations</h2>

        <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Skeleton v-for="i in 4" :key="i" class="h-24 w-full" />
        </div>

        <template v-else-if="stats">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <StatCard
                    title="Confirmed"
                    :value="stats.counts.confirmed ?? 0"
                />
                <StatCard
                    title="Submitted"
                    :value="stats.counts.submitted ?? 0"
                />
                <StatCard
                    title="Approved"
                    :value="stats.counts.approved ?? 0"
                />
                <StatCard
                    title="Cancelled / Rejected"
                    :value="
                        (stats.counts.cancelled ?? 0) +
                        (stats.counts.rejected ?? 0)
                    "
                />
            </div>

            <div class="overflow-x-auto rounded-xl border border-border">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-text-muted"
                        >
                            <th class="p-3 font-medium">Type</th>
                            <th class="p-3 text-right font-medium">Sold</th>
                            <th class="p-3 text-right font-medium">Quota</th>
                            <th class="p-3 text-right font-medium">
                                Remaining
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="stats.types.length">
                            <tr
                                v-for="type in stats.types"
                                :key="type.id"
                                class="border-b border-border last:border-0"
                            >
                                <td class="p-3 font-medium text-text">
                                    {{ type.name }}
                                </td>
                                <td
                                    class="p-3 text-right tabular-nums text-text-muted"
                                >
                                    {{ type.sold }}
                                </td>
                                <td
                                    class="p-3 text-right tabular-nums text-text-muted"
                                >
                                    {{ type.capacity ?? '—' }}
                                </td>
                                <td
                                    class="p-3 text-right tabular-nums text-text-muted"
                                >
                                    {{ type.remaining ?? '—' }}
                                </td>
                            </tr>
                        </template>
                        <tr v-else>
                            <td class="p-3 text-text-muted" colspan="4">
                                No registration types yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="text-sm text-danger">
            Could not load registration stats.
        </p>
    </div>
</template>
