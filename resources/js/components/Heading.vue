<script setup lang="ts">
/**
 * A section heading. `as` defaults to `h2` because the usual caller sits
 * under a layout or a screen-reader-only `h1` that already owns the
 * document's top level; a page whose visible title *is* the page title
 * passes `as="h1"` rather than leaving the document without one (WCAG 1.3.1).
 */
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    as?: 'h1' | 'h2' | 'h3';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
    as: 'h2',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <component
            :is="as"
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
