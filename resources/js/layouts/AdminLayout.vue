<script setup lang="ts">
/**
 * Operator console shell (ADR-0029). Navigation comes from the Platform
 * registry via the shared `admin` prop — modules contribute entries, this
 * layout knows none of them. Built on the same shadcn sidebar primitives as
 * the brand-facing shell (AppSidebarLayout): per-module collapsible groups, a
 * live search filter and pinned favorites live in `AdminSidebar`, ported
 * concept from LanCore's AppSidebar/NavFavorites/CollapsibleSidebarGroup.
 */
import { usePage } from '@inertiajs/vue3';
import AdminSidebar from '@/components/admin/AdminSidebar.vue';
import AppContent from '@/components/AppContent.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import EnvironmentRibbon from '@/components/EnvironmentRibbon.vue';
import SkipToContent from '@/components/SkipToContent.vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import { useCookieSettingsDialog } from '@/composables/useCookieSettingsDialog';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const isOpen = usePage().props.sidebarOpen;

// The console's own way to withdraw functional storage (WP194 §3.6): the
// only host where `sidebar_state` is written, so it is the only host that
// needs a way to say "stop remembering that too" without leaving for a
// brand page. Rendered only when the dialog shipped — same skip the footer
// already uses for a not-yet-built dialog behind a footer entry.
const cookieSettings = useCookieSettingsDialog();
const cookieSettingsDialog = resolveRegisteredComponent(
    'platform/dialogs/cookie-settings',
);
</script>

<template>
    <SidebarProvider :default-open="isOpen">
        <SkipToContent />
        <EnvironmentRibbon />
        <AdminSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="flex-1 p-6 lg:p-8">
                <slot />
            </div>
        </AppContent>
        <Toaster />
        <component
            :is="cookieSettingsDialog"
            v-if="cookieSettingsDialog"
            :open="cookieSettings.isOpen.value"
            @close="cookieSettings.close()"
        />
    </SidebarProvider>
</template>
