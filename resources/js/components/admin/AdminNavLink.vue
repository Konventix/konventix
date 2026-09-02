<script setup lang="ts">
// A single nav row that pairs a link with a pin/favorite toggle (ADR-0029),
// ported concept from LanCore's SidebarLink.
import { Link } from '@inertiajs/vue3';
import { Pin, PinOff } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import {
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const props = withDefaults(
    defineProps<{
        label: string;
        url: string;
        icon: Component;
        pinned: boolean;
        /**
         * Not an Inertia page (Horizon, Pulse, the health page): a plain
         * anchor lets the browser load it whole. An Inertia `Link` would
         * fetch its HTML over XHR and show it in the error modal — the
         * "empty page with the Horizon background" of GitLab #61.
         */
        external?: boolean;
    }>(),
    {
        external: false,
    },
);

const emit = defineEmits<{
    (e: 'togglePin'): void;
}>();

const { isCurrentUrl } = useCurrentUrl();

const isActive = computed(() => isCurrentUrl(props.url));
</script>

<template>
    <SidebarMenuItem>
        <SidebarMenuButton as-child :is-active="isActive" :tooltip="label">
            <!--
                `is-active` sets data-active, which styles the row and says
                nothing to a screen reader; aria-current is what announces
                "you are here" (WCAG 1.3.1).
            -->
            <component
                :is="external ? 'a' : Link"
                :href="url"
                :aria-current="isActive ? 'page' : undefined"
            >
                <component :is="icon" />
                <span>{{ label }}</span>
            </component>
        </SidebarMenuButton>
        <SidebarMenuAction
            show-on-hover
            @click.stop.prevent="emit('togglePin')"
        >
            <PinOff v-if="pinned" class="size-4" />
            <Pin v-else class="size-4" />
            <span class="sr-only"
                >{{ pinned ? 'Unpin' : 'Pin' }} {{ label }}</span
            >
        </SidebarMenuAction>
    </SidebarMenuItem>
</template>
