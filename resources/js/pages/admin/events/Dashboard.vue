<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import AdmissionEventStatsCard from '@/components/admission/EventStatsCard.vue';
import RegistrationEventStatsCard from '@/components/registration/EventStatsCard.vue';
import { Button } from '@/components/ui/button';

/**
 * Composes Event's own header with contributions from other modules'
 * `SlotRegistry` entries for the `event.admin.dashboard` slot (ADR-0003
 * enforcement point 3, docs/decisions/0003 "slots are an activation gate
 * point"). The component map below is the frontend half of that registry —
 * same pattern as `StaticPage.vue`'s block component map — so this page
 * never imports a module's card by guessing a path; it only resolves the
 * string contract each module's ServiceProvider declares in its own
 * `registerSlots()`.
 *
 * "Manual refresh" (LanCore's `Dashboard.vue` pattern) bumps `refreshToken`,
 * which each card watches to re-fetch its own endpoint — there is no shared
 * Inertia prop to reload, since each card owns its own client-side fetch.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type EventHeader = {
    id: string;
    name: string;
    state: string;
    startsAt: string;
    endsAt: string;
    timezone: string;
};

defineProps<{
    event: EventHeader;
    slots: SlotContribution[];
}>();

const componentMap: Record<string, unknown> = {
    'registration/EventStatsCard': RegistrationEventStatsCard,
    'admission/EventStatsCard': AdmissionEventStatsCard,
};

const stateBadgeClass: Record<string, string> = {
    draft: 'bg-surface-muted text-text-muted',
    announced: 'bg-accent/15 text-accent',
    published: 'bg-success/15 text-success',
    running: 'bg-success/15 text-success',
    concluded: 'bg-surface-muted text-text-muted',
    archived: 'bg-surface-muted text-text-muted',
    cancelled: 'bg-danger/15 text-danger',
};

const refreshToken = ref(0);
const refreshing = ref(false);

function refresh(): void {
    refreshing.value = true;
    refreshToken.value += 1;

    // Cards resolve their own fetch promise; this button only needs to show
    // "something happened" briefly rather than track every card's request.
    window.setTimeout(() => {
        refreshing.value = false;
    }, 400);
}
</script>

<template>
    <Head :title="event.name" />

    <div class="flex flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-semibold text-text">
                        {{ event.name }}
                    </h1>
                    <span
                        class="rounded px-1.5 py-0.5 text-xs"
                        :class="
                            stateBadgeClass[event.state] ??
                            'bg-surface-muted text-text-muted'
                        "
                    >
                        {{ event.state }}
                    </span>
                </div>
                <p class="text-sm text-text-muted">
                    {{ event.startsAt }} – {{ event.endsAt }} ({{
                        event.timezone
                    }})
                </p>
            </div>

            <Button
                variant="outline"
                size="sm"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw class="size-4" :class="{ 'animate-spin': refreshing }" />
                Refresh
            </Button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <template
                v-for="slot in slots"
                :key="`${slot.module}-${slot.component}`"
            >
                <component
                    :is="componentMap[slot.component]"
                    v-if="componentMap[slot.component]"
                    :event-id="event.id"
                    :refresh-token="refreshToken"
                    v-bind="slot.props"
                />
            </template>
        </div>
    </div>
</template>
