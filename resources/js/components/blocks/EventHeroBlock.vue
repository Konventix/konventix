<script setup lang="ts">
import { computed } from 'vue';

/**
 * Props are resolved server-side by
 * `Konventix\Event\Blocks\EventHeroBlock::resolve()`. `startsAt`/`endsAt`
 * are wall-clock strings (`Y-m-d H:i:s`), not instants (ADR-0027) — they are
 * formatted here as plain text, never through `new Date(...)`, which would
 * silently reinterpret them in the visitor's browser timezone.
 */
const props = defineProps<{
    found: boolean;
    name: string | null;
    startsAt: string | null;
    endsAt: string | null;
    timezone: string | null;
    venueCity: string | null;
}>();

function formatWallClock(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const [datePart, timePart] = value.split(' ');
    const [year, month, day] = datePart.split('-');

    return `${day}.${month}.${year}, ${timePart?.slice(0, 5)}`;
}

const startsLabel = computed(() => formatWallClock(props.startsAt));
const endsLabel = computed(() => formatWallClock(props.endsAt));
</script>

<template>
    <section
        v-if="found"
        class="rounded-xl border border-border bg-surface-raised p-6"
    >
        <h2 class="text-2xl font-semibold text-text">{{ name }}</h2>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs tracking-wide text-text-muted uppercase">
                    When
                </dt>
                <dd class="mt-1 text-sm text-text">
                    {{ startsLabel
                    }}<template v-if="endsLabel"> – {{ endsLabel }}</template>
                    <span v-if="timezone" class="text-text-muted">
                        ({{ timezone }})</span
                    >
                </dd>
            </div>
            <div v-if="venueCity">
                <dt class="text-xs tracking-wide text-text-muted uppercase">
                    Where
                </dt>
                <dd class="mt-1 text-sm text-text">{{ venueCity }}</dd>
            </div>
        </dl>
    </section>
</template>
