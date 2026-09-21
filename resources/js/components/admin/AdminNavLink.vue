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
        /**
         * A count the entry carries — open support requests today (D-SUP-13).
         * `null` means the entry has no badge at all; `0` means it has one
         * and there is nothing waiting, and neither renders: a pill saying
         * "0 open" is a thing to read and then ignore, every page load.
         */
        badge?: number | null;
    }>(),
    {
        external: false,
        badge: null,
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
                <!--
                    The number is decoration for a screen reader — "Support
                    requests 3" says nothing — so the pill is hidden from
                    the accessibility tree and an explicit label takes its
                    place inside the link's own name (WCAG 1.3.1).
                -->
                <span
                    v-if="badge !== null && badge > 0"
                    class="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1.5 py-0.5 text-xs font-medium text-on-accent tabular-nums"
                    aria-hidden="true"
                    >{{ badge }}</span
                >
                <span v-if="badge !== null && badge > 0" class="sr-only"
                    >{{ badge }} open</span
                >
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
