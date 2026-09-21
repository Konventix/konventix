<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { BrandMenuEntry } from '@/types/navigation';

/**
 * The brand's own entries in the mobile menu (Task 20): leaves as rows in
 * the same style as the module rows, parents as nested collapsibles whose
 * children indent beneath them. Nothing here decides who sees what — the
 * server resolved the list for this viewer already.
 */
defineProps<{ entries: BrandMenuEntry[] }>();

const { isCurrentUrl } = useCurrentUrl();

const rowClass =
    'rounded-lg px-3 py-2 text-sm text-text-muted transition hover:bg-surface-muted hover:text-text aria-[current=page]:bg-subtle aria-[current=page]:text-text';
</script>

<template>
    <template
        v-for="entry in entries"
        :key="`${entry.label}:${entry.url ?? ''}`"
    >
        <Collapsible v-if="entry.children.length > 0">
            <CollapsibleTrigger as-child>
                <button
                    type="button"
                    :class="[
                        rowClass,
                        'group flex w-full items-center justify-between text-left',
                    ]"
                >
                    {{ entry.label }}
                    <ChevronDown
                        class="size-4 transition group-data-[state=open]:rotate-180"
                        aria-hidden="true"
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent class="flex flex-col gap-1 pl-4">
                <component
                    :is="child.external ? 'a' : Link"
                    v-for="child in entry.children"
                    :key="`${child.label}:${child.url ?? ''}`"
                    :href="child.url ?? '#'"
                    :target="child.newTab ? '_blank' : undefined"
                    :rel="child.newTab ? 'noopener noreferrer' : undefined"
                    :aria-current="
                        child.url && isCurrentUrl(child.url)
                            ? 'page'
                            : undefined
                    "
                    :class="rowClass"
                >
                    {{ child.label }}
                </component>
            </CollapsibleContent>
        </Collapsible>
        <component
            :is="entry.external ? 'a' : Link"
            v-else
            :href="entry.url ?? '#'"
            :target="entry.newTab ? '_blank' : undefined"
            :rel="entry.newTab ? 'noopener noreferrer' : undefined"
            :aria-current="
                entry.url && isCurrentUrl(entry.url) ? 'page' : undefined
            "
            :class="rowClass"
        >
            {{ entry.label }}
        </component>
    </template>
</template>
