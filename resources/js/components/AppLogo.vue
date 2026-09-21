<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * The resolved brand's mark. Every asset is optional and the bundled Konventix
 * mark stands in for a brand that has uploaded nothing — the normal state for
 * a new brand, not an error. The mark is rendered plain in the text colour, no
 * tile: the brand rules allow it only in Ink on light or light on dark, with
 * no bounding shape around it.
 *
 * Light and dark variants are swapped by CSS rather than by reading the
 * appearance state in JavaScript, so the right mark is already in the markup
 * before hydration and never flashes the wrong one. A brand that uploaded
 * only a light mark wears it in both themes.
 */
type Props = {
    /**
     * Whether to render the brand's name beside the mark. On when the mark
     * is decorative chrome (the topbar), off where the logo stands alone.
     */
    showName?: boolean;
};

const { showName = true } = defineProps<Props>();

const brand = computed(() => usePage().props.brand);

/**
 * The image is decorative when the name is rendered beside it — a screen
 * reader announcing "SXLan SXLan" is worse than one announcing it once. With
 * the name hidden the image carries the accessible name itself.
 */
const logoAlt = computed(() =>
    showName ? '' : (brand.value.logoAlt ?? brand.value.name),
);
</script>

<template>
    <span class="flex items-center gap-2">
        <template v-if="brand.logoUrl">
            <img
                :src="brand.logoUrl"
                :alt="logoAlt"
                class="h-8 w-auto max-w-40 object-contain"
                :class="{ 'dark:hidden': brand.logoDarkUrl }"
            />
            <img
                v-if="brand.logoDarkUrl"
                :src="brand.logoDarkUrl"
                :alt="logoAlt"
                class="hidden h-8 w-auto max-w-40 object-contain dark:block"
            />
        </template>
        <AppLogoIcon
            v-else
            class="h-8 w-auto shrink-0 text-foreground"
            :role="showName ? undefined : 'img'"
            :aria-label="showName ? undefined : logoAlt"
            :aria-hidden="showName ? 'true' : undefined"
        />
        <span v-if="showName" class="truncate text-sm font-semibold">
            {{ brand.name }}
        </span>
    </span>
</template>
