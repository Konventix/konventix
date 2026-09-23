<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as showEvent } from '@/routes/event';
import CountdownTimer from '../countdown/CountdownTimer.vue';

/**
 * Props are resolved server-side by
 * `Konventix\Event\Blocks\CountdownBlock::resolve()` — `targetIso` is
 * already a real instant (ADR-0027's `startsAtInstant()`), so this component
 * hands it straight to `CountdownTimer` without any timezone math of its
 * own. `found` is false when the stored event ULID no longer resolves
 * (deleted, or a different brand); the block then renders nothing rather
 * than a broken countdown. `slug` is set only when the event is visible on
 * its landing page (the hero's rule); the event name links there then and
 * stays plain text otherwise (bugfix round 2026-09-21, Task 17b).
 */
defineProps<{
    found: boolean;
    label: string | null;
    eventName: string | null;
    targetIso: string | null;
    slug: string | null;
}>();
</script>

<template>
    <section
        v-if="found && targetIso"
        class="flex flex-col items-center gap-4 text-center"
    >
        <p class="text-sm font-medium tracking-wide text-text-muted uppercase">
            {{ label ?? $t('Countdown')
            }}<template v-if="eventName">
                —
                <Link
                    v-if="slug"
                    :href="showEvent(slug).url"
                    class="underline-offset-4 hover:underline"
                >
                    {{ eventName }}
                </Link>
                <template v-else>{{ eventName }}</template>
            </template>
        </p>
        <CountdownTimer :target-iso="targetIso" />
    </section>
</template>
