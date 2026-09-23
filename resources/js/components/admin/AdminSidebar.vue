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
import { Button } from '@/components/ui/button';
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
import { useCookieSettingsDialog } from '@/composables/useCookieSettingsDialog';
import { iconFor } from '@/lib/navIcons';
import { dashboard } from '@/routes/admin';

type AdminNavItem = {
    module: string;
    label: string;
    url: string;
    icon: string | null;
    order: number;
    external: boolean;
    /** Resolved per request by the backend's `NavigationItem` (D-SUP-13). */
    badge: number | null;
};

/**
 * 'game-ops' -> 'Game Ops' — group label from the module's kebab-case name.
 * The result is a catalog key: the template translates it where it renders.
 */
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
const { open: openCookieSettings } = useCookieSettingsDialog();

/**
 * A pinned favorite stores only a label and a URL; whether that URL is an
 * Inertia page is known here, from the entry it was pinned from.
 */
const externalUrls = computed(
    () =>
        new Set(
            (admin.value?.navigation ?? [])
                .filter((item) => item.external)
                .map((item) => item.url),
        ),
);

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
                        <!--
                            Collapsed, the logo alone (#320). Its own square
                            box fills the 32 px collapsed button and keeps the
                            mark out of the button's `[&>svg]:size-4`; the two
                            text lines hide rather than show a letter each
                            through the button's overflow.
                        -->
                        <Link :href="dashboard().url">
                            <div
                                class="flex aspect-square size-8 shrink-0 items-center justify-center"
                            >
                                <AppLogoIcon
                                    class="size-6 fill-current text-sidebar-foreground"
                                />
                            </div>
                            <div
                                class="grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden"
                            >
                                <span class="truncate text-sm font-semibold">
                                    {{ admin?.operatorName ?? $t('Operator') }}
                                </span>
                                <span
                                    class="truncate text-xs text-sidebar-foreground/60"
                                >
                                    {{ $t('Admin console') }}
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
                :external-urls="externalUrls"
                @unpin="toggle"
                @reorder="reorder"
            />

            <AdminNavGroup
                v-for="group in groups"
                :key="group.module"
                :label="$t(group.label)"
                :search-active="searchActive"
            >
                <SidebarMenu>
                    <AdminNavLink
                        v-for="item in group.items"
                        :key="item.url"
                        :label="item.label"
                        :url="item.url"
                        :icon="iconFor(item.icon)"
                        :external="item.external"
                        :badge="item.badge"
                        :pinned="isPinned(item.url)"
                        @toggle-pin="toggle(item)"
                    />
                </SidebarMenu>
            </AdminNavGroup>
        </SidebarContent>

        <SidebarFooter>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="justify-start text-text-muted"
                @click="openCookieSettings()"
            >
                {{ $t('Cookie settings') }}
            </Button>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
