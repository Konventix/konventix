<script setup lang="ts">
/**
 * Bypass Blocks (WCAG 2.4.1, level A — BFSG). The console sidebar is dozens
 * of links deep and repeats on every page; without this a keyboard or
 * switch user tabs through all of it before reaching the page they asked
 * for.
 *
 * Hidden until focused, and rendered first inside the shell so it is the
 * first thing in the tab order. It targets `AppContent`'s
 * `<main id="main-content" tabindex="-1">`: the negative tabindex keeps
 * `<main>` out of the tab order while still letting the fragment link move
 * focus into it — without it the browser scrolls but focus stays on the
 * link, so the next Tab lands back in the navigation and the skip skips
 * nothing.
 */
</script>

<template>
    <a
        href="#main-content"
        class="sr-only rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50"
    >
        Skip to content
    </a>
</template>
