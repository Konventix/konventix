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
import SkipToContent from '@/components/SkipToContent.vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
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
</script>

<template>
    <SidebarProvider :default-open="isOpen">
        <SkipToContent />
        <AdminSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="flex-1 p-6 lg:p-8">
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </SidebarProvider>
</template>
