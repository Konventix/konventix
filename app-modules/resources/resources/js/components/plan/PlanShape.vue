<script setup lang="ts">
import { computed } from 'vue';
import { DEFAULT_POINT_RADIUS_MM, polygonPoints } from './geometry';
import type { Point } from './transform';

/**
 * One document shape as SVG (design §5): rect and polygon from their
 * top-left origin, ellipse and point from their centre, rotated about the
 * origin. Fill and stroke arrive as `var(--token)` strings from tokens.ts.
 */
type Shape = Konventix.Resources.Data.ShapeData;

const props = withDefaults(
    defineProps<{
        shape: Shape;
        origin: Point;
        rotation?: number;
        fill: string;
        stroke?: string;
        strokeWidth?: number;
        opacity?: number;
    }>(),
    { rotation: 0, stroke: 'none', strokeWidth: 0, opacity: 1 },
);

const rotate = computed(() =>
    props.rotation === 0
        ? undefined
        : `rotate(${props.rotation} ${props.origin.x} ${props.origin.y})`,
);
</script>

<template>
    <rect
        v-if="shape.type === 'rect'"
        :x="origin.x"
        :y="origin.y"
        :width="shape.w ?? 0"
        :height="shape.h ?? 0"
        :rx="shape.radius ?? 0"
        :fill="fill"
        :stroke="stroke"
        :stroke-width="strokeWidth"
        :opacity="opacity"
        :transform="rotate"
    />
    <ellipse
        v-else-if="shape.type === 'ellipse'"
        :cx="origin.x"
        :cy="origin.y"
        :rx="shape.rx ?? 0"
        :ry="shape.ry ?? 0"
        :fill="fill"
        :stroke="stroke"
        :stroke-width="strokeWidth"
        :opacity="opacity"
        :transform="rotate"
    />
    <circle
        v-else-if="shape.type === 'point'"
        :cx="origin.x"
        :cy="origin.y"
        :r="shape.radius ?? DEFAULT_POINT_RADIUS_MM"
        :fill="fill"
        :stroke="stroke"
        :stroke-width="strokeWidth"
        :opacity="opacity"
    />
    <polygon
        v-else
        :points="polygonPoints(shape, origin)"
        :fill="fill"
        :stroke="stroke"
        :stroke-width="strokeWidth"
        :opacity="opacity"
        :transform="rotate"
    />
</template>
