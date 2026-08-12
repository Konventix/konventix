<script setup lang="ts">
// Live client-side filter for the admin sidebar (ADR-0029), ported concept
// from LanCore's SidebarSearch.
import { Search, X } from '@lucide/vue';

const model = defineModel<string>({ default: '' });
</script>

<template>
    <div class="relative px-2 group-data-[collapsible=icon]:hidden">
        <Search
            class="pointer-events-none absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-sidebar-foreground/60"
            aria-hidden="true"
        />
        <input
            v-model="model"
            type="text"
            placeholder="Search admin…"
            aria-label="Search admin navigation"
            class="h-7 w-full rounded-md border border-sidebar-border bg-sidebar-subtle/40 pr-7 pl-7 text-sm text-sidebar-foreground transition-colors outline-none placeholder:text-sidebar-foreground/50 focus-visible:border-sidebar-ring focus-visible:ring-1 focus-visible:ring-sidebar-ring"
        />
        <button
            v-if="model.length > 0"
            type="button"
            class="absolute top-1/2 right-3 -translate-y-1/2 rounded-sm p-0.5 text-sidebar-foreground/60 hover:text-sidebar-foreground"
            aria-label="Clear search"
            @click="model = ''"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
