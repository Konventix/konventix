<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Ported from LanCore's `components/countdown/CountdownTimer.vue`, converted
 * to semantic tokens (`bg-card` → `bg-surface-raised`, `text-muted-foreground`
 * → `text-text-muted`, ...). Behavior is unchanged: four tabular-nums segment
 * cards, ticking every second via `@vueuse/core`'s `useNow`.
 */
const props = defineProps<{
    /** ISO-8601 instant the countdown is targeting, resolved server-side. */
    targetIso: string;
}>();

const now = useNow({ interval: 1000 });

const remaining = computed(() => {
    const target = new Date(props.targetIso).getTime();
    const diffMs = Math.max(0, target - now.value.getTime());
    const totalSeconds = Math.floor(diffMs / 1000);

    return {
        days: Math.floor(totalSeconds / 86400),
        hours: Math.floor((totalSeconds % 86400) / 3600),
        minutes: Math.floor((totalSeconds % 3600) / 60),
        seconds: totalSeconds % 60,
    };
});

const segments = computed(() => [
    { key: 'days', label: trans('Days'), value: remaining.value.days },
    { key: 'hours', label: trans('Hours'), value: remaining.value.hours },
    { key: 'minutes', label: trans('Minutes'), value: remaining.value.minutes },
    { key: 'seconds', label: trans('Seconds'), value: remaining.value.seconds },
]);
</script>

<template>
    <div
        class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        role="timer"
        aria-live="polite"
    >
        <div
            v-for="segment in segments"
            :key="segment.key"
            class="rounded-lg border border-border bg-surface-raised px-4 py-6 text-center shadow-sm"
        >
            <div
                class="text-4xl font-semibold text-text tabular-nums sm:text-5xl"
            >
                {{ segment.value.toString().padStart(2, '0') }}
            </div>
            <div class="mt-2 text-xs tracking-wider text-text-muted uppercase">
                {{ segment.label }}
            </div>
        </div>
    </div>
</template>
