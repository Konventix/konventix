<script setup lang="ts">
// Pinned admin shortcuts (ADR-0029), ported concept from LanCore's
// NavFavorites.
import { Link } from '@inertiajs/vue3';
import { PinOff } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { AdminFavorite } from '@/composables/useAdminFavorites';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

defineProps<{
    favorites: AdminFavorite[];
}>();

const emit = defineEmits<{
    (e: 'unpin', favorite: AdminFavorite): void;
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-if="favorites.length > 0" class="px-2 py-0">
        <SidebarGroupLabel>Favorites</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="favorite in favorites" :key="favorite.id">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(favorite.url)"
                    :tooltip="favorite.label"
                >
                    <Link :href="favorite.url">
                        <span>{{ favorite.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuAction
                    show-on-hover
                    @click.stop.prevent="emit('unpin', favorite)"
                >
                    <PinOff class="size-4" />
                    <span class="sr-only">Unpin {{ favorite.label }}</span>
                </SidebarMenuAction>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
