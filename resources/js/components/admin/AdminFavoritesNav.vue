<script setup lang="ts">
// Pinned admin shortcuts (ADR-0029), ported concept from LanCore's
// NavFavorites. The list is in the user's own order and freely
// drag-reorderable (ADR-0042): a drag reorders the local copy live and the new
// order is persisted once, on drop. Keyboard users move a row with
// ArrowUp/ArrowDown on its grip handle.
import { Link } from '@inertiajs/vue3';
import { GripVertical, PinOff } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import { useDragReorder } from '@/composables/useDragReorder';

const props = defineProps<{
    favorites: AdminFavorite[];
}>();

const emit = defineEmits<{
    (e: 'unpin', favorite: AdminFavorite): void;
    (e: 'reorder', ids: string[]): void;
}>();

const { isCurrentUrl } = useCurrentUrl();

/**
 * Local copy so a drag reorders instantly instead of waiting for the server;
 * the shared `admin.favorites` prop re-syncs it after every visit, which also
 * covers pins and unpins made elsewhere in the shell.
 */
const items = ref<AdminFavorite[]>([...props.favorites]);

watch(
    () => props.favorites,
    (favorites) => {
        items.value = [...favorites];
    },
);

function persistOrder(): void {
    const ids = items.value.map((favorite) => favorite.id);
    const stored = props.favorites.map((favorite) => favorite.id);

    if (
        ids.length === stored.length &&
        ids.every((id, index) => id === stored[index])
    ) {
        return;
    }

    emit('reorder', ids);
}

const {
    draggingIndex,
    onDragStart,
    onDragEnter,
    onDrop,
    onDragEnd,
    onHandleKeydown,
} = useDragReorder<AdminFavorite>({
    get: () => items.value,
    set: (next) => (items.value = next),
    onSettled: persistOrder,
});
</script>

<template>
    <SidebarGroup v-if="items.length > 0" class="px-2 py-0">
        <SidebarGroupLabel>Favorites</SidebarGroupLabel>
        <p id="admin-favorites-reorder-hint" class="sr-only">
            Drag a favorite to reorder it, or focus its reorder handle and press
            the up or down arrow key.
        </p>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="(favorite, index) in items"
                :key="favorite.id"
                draggable="true"
                :class="draggingIndex === index && 'opacity-60'"
                @dragstart="onDragStart(index, $event)"
                @dragenter.prevent="onDragEnter(index)"
                @dragover.prevent
                @drop.prevent="onDrop"
                @dragend="onDragEnd"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(favorite.url)"
                    :tooltip="favorite.label"
                >
                    <Link :href="favorite.url" draggable="false">
                        <span>{{ favorite.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuAction
                    show-on-hover
                    class="right-7 cursor-grab active:cursor-grabbing"
                    aria-describedby="admin-favorites-reorder-hint"
                    @keydown="onHandleKeydown(index, $event)"
                >
                    <GripVertical class="size-4" />
                    <span class="sr-only">
                        Reorder {{ favorite.label }}, position
                        {{ index + 1 }} of {{ items.length }}
                    </span>
                </SidebarMenuAction>
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
