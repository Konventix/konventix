<script setup lang="ts">
import CountdownTimer from '../countdown/CountdownTimer.vue';

/**
 * Props are resolved server-side by
 * `Konventix\Event\Blocks\CountdownBlock::resolve()` — `targetIso` is
 * already a real instant (ADR-0027's `startsAtInstant()`), so this component
 * hands it straight to `CountdownTimer` without any timezone math of its
 * own. `found` is false when the stored event ULID no longer resolves
 * (deleted, or a different brand); the block then renders nothing rather
 * than a broken countdown.
 */
defineProps<{
    found: boolean;
    label: string | null;
    eventName: string | null;
    targetIso: string | null;
}>();
</script>

<template>
    <section
        v-if="found && targetIso"
        class="flex flex-col items-center gap-4 text-center"
    >
        <p class="text-sm font-medium tracking-wide text-text-muted uppercase">
            {{ label ?? 'Countdown' }}<template v-if="eventName">
                — {{ eventName }}</template
            >
        </p>
        <CountdownTimer :target-iso="targetIso" />
    </section>
</template>
