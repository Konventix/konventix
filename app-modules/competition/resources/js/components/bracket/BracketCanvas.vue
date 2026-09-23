<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { page as matchPage } from '@/routes/competition/match';
import { bracketSections } from './bracketModel';
import { useBracketCanvas } from './useBracketCanvas';

/**
 * The bracket as a picture you can pan, zoom and click into (design §10,
 * D-CMP-22) — LanBrackets' renderer, ported in `useBracketCanvas`.
 *
 * It is `role="img"`: a canvas says nothing to a screen reader, so its label
 * points at the table, which carries the same pairings. The zoom controls
 * are real buttons, and a click on a match opens it the way the table's link
 * does.
 */
const props = defineProps<{
    bracket: Konventix.Competition.Data.BracketData;
    eventSlug: string;
    competitionSlug: string;
}>();

const sections = computed(() => bracketSections(props.bracket));
const connections = computed(() => props.bracket.connections);

const {
    canvas,
    isDragging,
    scale,
    fitToScreen,
    zoomIn,
    zoomOut,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    onWheel,
} = useBracketCanvas({
    sections,
    connections,
    onOpen: (matchId) =>
        router.visit(
            matchPage({
                slug: props.eventSlug,
                competition: props.competitionSlug,
                match: matchId,
            }).url,
        ),
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" @click="zoomOut">
                <span aria-hidden="true">−</span>
                <span class="sr-only">{{ $t('Zoom out') }}</span>
            </Button>
            <Button variant="outline" size="sm" @click="zoomIn">
                <span aria-hidden="true">+</span>
                <span class="sr-only">{{ $t('Zoom in') }}</span>
            </Button>
            <Button variant="outline" size="sm" @click="fitToScreen">
                {{ $t('Fit to screen') }}
            </Button>
            <span class="text-xs text-text-muted" aria-live="off">
                {{ Math.round(scale * 100) }} %
            </span>
        </div>

        <canvas
            ref="canvas"
            role="img"
            :aria-label="
                $t(
                    'The bracket as a graphic. The same pairings are in the table view.',
                )
            "
            class="h-[32rem] w-full touch-none rounded-xl border border-border"
            :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel="onWheel"
        />
    </div>
</template>
