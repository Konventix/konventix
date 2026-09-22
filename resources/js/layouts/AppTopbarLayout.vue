<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppTopbar from '@/components/AppTopbar.vue';
import EmailVerificationBanner from '@/components/EmailVerificationBanner.vue';
import EnvironmentRibbon from '@/components/EnvironmentRibbon.vue';
import SkipToContent from '@/components/SkipToContent.vue';
import { Toaster } from '@/components/ui/sonner';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { toasterOffsets } from '@/lib/toasterOffset';

// Support's floating report button (ADR-0063, #88), contributed by the
// module and resolved by name — the shell learns nothing about it and
// renders nothing when it is absent, the same skip AdminLayout already
// uses for the cookie dialog.
const supportLauncher = resolveRegisteredComponent('support/SupportLauncher');

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
        <EnvironmentRibbon />
        <EmailVerificationBanner />
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
        <!-- Clears the feedback button while it is mounted (#319). -->
        <Toaster v-bind="toasterOffsets(supportLauncher !== null)" />
        <component :is="supportLauncher" v-if="supportLauncher" />
    </div>
</template>
