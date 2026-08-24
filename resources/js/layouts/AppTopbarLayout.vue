<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppTopbar from '@/components/AppTopbar.vue';
import SkipToContent from '@/components/SkipToContent.vue';
import { Toaster } from '@/components/ui/sonner';

/**
 * The brand-facing shell: one topbar for visitors and members alike, the
 * page, then the operator's footer. There is no brand sidebar — a brand host
 * has a topbar, the operator console has a sidebar (ADR-0029), and neither
 * has both.
 *
 * `main` carries the skip link's target: `tabindex="-1"` keeps the landmark
 * out of the tab order while letting the fragment link move focus into it,
 * without which the skip scrolls but leaves focus in the navigation
 * (WCAG 2.4.1).
 */
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <SkipToContent />
        <AppTopbar />
        <!--
            No container of its own: pages own their width and padding here
            the same way they did inside the sidebar inset, and a full-bleed
            hero must be able to reach the edges.
        -->
        <main id="main-content" tabindex="-1" class="flex-1">
            <slot />
        </main>
        <AppFooter />
        <Toaster />
    </div>
</template>
