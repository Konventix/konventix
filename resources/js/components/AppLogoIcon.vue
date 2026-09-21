<!--
    The Konventix K/X monogram, the four canonical paths of
    konventix-brand/assets/logo/mark/konventix-mark-ink.svg (viewBox 701 × 732,
    unit x = 182 = the stem width). Filled with the current text colour so it
    reads Ink on light and light on dark chrome; never redraw or recolour parts.
-->
<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 701 732"
        :class="className"
        v-bind="$attrs"
    >
        <g fill="currentColor">
            <path d="M0 0H182V254L298 364 182 474V730H0Z" />
            <path d="M464 0H701L348 316 228 208Z" />
            <path d="M513 254V474L397 364Z" />
            <path d="M348 412L701 732H459L228 514Z" />
        </g>
    </svg>
</template>
