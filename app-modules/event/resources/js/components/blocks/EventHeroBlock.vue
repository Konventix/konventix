<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, useId } from 'vue';
import { show as showEvent } from '@/routes/event';

/**
 * Props are resolved server-side by
 * `Konventix\Event\Blocks\EventHeroBlock::resolve()`. `startsAt`/`endsAt`
 * are wall-clock strings (`Y-m-d H:i:s`), not instants (ADR-0027) — they are
 * formatted here as plain text, never through `new Date(...)`, which would
 * silently reinterpret them in the visitor's browser timezone.
 *
 * `slug` is set only when the event is visible on its landing page; then the
 * whole hero is one `<Link>` to it (the house pattern of `event/Index.vue`),
 * one tab stop named by its heading. Otherwise it stays a plain section — a
 * draft's landing page would 404.
 */
const props = defineProps<{
    found: boolean;
    name: string | null;
    startsAt: string | null;
    endsAt: string | null;
    timezone: string | null;
    venueCity: string | null;
    slug: string | null;
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

const headingId = useId();

const linkAttributes = computed(() =>
    props.slug
        ? { href: showEvent(props.slug).url, 'aria-labelledby': headingId }
        : {},
);
</script>

<template>
    <component
        :is="slug ? Link : 'section'"
        v-if="found"
        v-bind="linkAttributes"
        class="block rounded-xl border border-border bg-surface-raised p-6"
        :class="slug ? 'transition hover:border-accent' : ''"
    >
        <h2 :id="headingId" class="text-2xl font-semibold text-text">
            {{ name }}
        </h2>
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
    </component>
</template>
