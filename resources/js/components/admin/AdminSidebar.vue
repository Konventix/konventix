<script setup lang="ts">
// Operator console shell (ADR-0029), ported concept from LanCore's
// AppSidebar: collapsible groups — one per module, derived from the shared
// `admin.navigation` prop Platform's NavigationRegistry contributes — plus a
// live client-side search filter and pinned favorites the user can drag into
// their own order (ADR-0042).
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminFavoritesNav from '@/components/admin/AdminFavoritesNav.vue';
import AdminNavGroup from '@/components/admin/AdminNavGroup.vue';
import AdminNavLink from '@/components/admin/AdminNavLink.vue';
import AdminSidebarSearch from '@/components/admin/AdminSidebarSearch.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarFooter,
} from '@/components/ui/sidebar';
import { useAdminFavorites } from '@/composables/useAdminFavorites';
import { iconFor } from '@/lib/navIcons';
import { dashboard } from '@/routes/admin';

type AdminNavItem = {
    module: string;
    label: string;
    url: string;
    icon: string | null;
    order: number;
};

/** 'game-ops' -> 'Game Ops' — group label from the module's kebab-case name. */
function humanizeModule(module: string): string {
    return module
        .split('-')
        .map((word) =>
            word.length > 0 ? word[0].toUpperCase() + word.slice(1) : word,
        )
        .join(' ');
}

const page = usePage();

const admin = computed(
    () =>
        page.props.admin as {
            operatorName: string | null;
            navigation: AdminNavItem[];
        } | null,
);

const { favorites, isPinned, toggle, reorder } = useAdminFavorites();

const searchQuery = ref('');
const searchActive = computed(() => searchQuery.value.trim().length > 0);

const groups = computed(() => {
    const byModule = new Map<string, AdminNavItem[]>();

    for (const item of admin.value?.navigation ?? []) {
        const items = byModule.get(item.module) ?? [];
        items.push(item);
        byModule.set(item.module, items);
    }

    const query = searchQuery.value.trim().toLowerCase();

    return Array.from(byModule.entries())
        .map(([module, items]) => ({
            module,
            label: humanizeModule(module),
            items:
                query === ''
                    ? items
                    : items.filter((item) =>
                          item.label.toLowerCase().includes(query),
                      ),
        }))
        .filter((group) => group.items.length > 0);
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard().url">
                            <AppLogoIcon
                                class="size-6 fill-current text-sidebar-foreground"
                            />
                            <div class="grid flex-1 text-left leading-tight">
                                <span class="truncate text-sm font-semibold">
                                    {{ admin?.operatorName ?? 'Operator' }}
                                </span>
                                <span
                                    class="truncate text-xs text-sidebar-foreground/60"
                                >
                                    Admin console
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <AdminSidebarSearch v-model="searchQuery" />
        </SidebarHeader>

        <SidebarContent>
            <AdminFavoritesNav
                :favorites="favorites"
                @unpin="toggle"
                @reorder="reorder"
            />

            <AdminNavGroup
                v-for="group in groups"
                :key="group.module"
                :label="group.label"
                :search-active="searchActive"
            >
                <SidebarMenu>
                    <AdminNavLink
                        v-for="item in group.items"
                        :key="item.url"
                        :label="item.label"
                        :url="item.url"
                        :icon="iconFor(item.icon)"
                        :pinned="isPinned(item.url)"
                        @toggle-pin="toggle(item)"
                    />
                </SidebarMenu>
            </AdminNavGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
