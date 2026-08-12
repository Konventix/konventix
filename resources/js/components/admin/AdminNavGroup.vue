<script setup lang="ts">
// One collapsible group per module (ADR-0029), ported concept from LanCore's
// CollapsibleSidebarGroup. Group-open state is local/session-only — no
// server persistence, unlike LanCore's SidebarGroupStateController.
import { ChevronDown } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
} from '@/components/ui/sidebar';

const props = defineProps<{
    label: string;
    /** When true (an active search query), the group force-expands. */
    searchActive: boolean;
}>();

const expanded = ref(true);

watch(
    () => props.searchActive,
    (active) => {
        if (active) {
            expanded.value = true;
        }
    },
);

function toggle(): void {
    if (props.searchActive) {
        return;
    }

    expanded.value = !expanded.value;
}
</script>

<template>
    <SidebarGroup class="py-1">
        <button
            type="button"
            class="group/collapse flex w-full items-center justify-between rounded-md px-2 py-1 text-left transition-colors hover:bg-sidebar-subtle/60"
            :aria-expanded="expanded"
            @click="toggle"
        >
            <SidebarGroupLabel as="span" class="pointer-events-none">
                {{ label }}
            </SidebarGroupLabel>
            <ChevronDown
                class="size-4 shrink-0 text-sidebar-foreground/60 transition-transform"
                :class="expanded ? '' : '-rotate-90'"
                aria-hidden="true"
            />
        </button>
        <SidebarGroupContent v-show="expanded">
            <slot />
        </SidebarGroupContent>
    </SidebarGroup>
</template>
