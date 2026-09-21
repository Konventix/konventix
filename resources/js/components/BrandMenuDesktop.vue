<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuTrigger,
} from '@/components/ui/navigation-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { BrandMenuEntry } from '@/types/navigation';

/**
 * The brand's own entries on the desktop topbar (Task 20), after the module
 * entries. A leaf is a link in the same style as the module links; a parent
 * opens its children through the reka-ui navigation menu, so hover, click
 * and the keyboard all work the way the primitive already provides.
 *
 * `external` and `newTab` come resolved from the server: a plain anchor for
 * a destination that is not an Inertia page, `target="_blank"` only where
 * the operator asked for it.
 */
defineProps<{ entries: BrandMenuEntry[] }>();

const { isCurrentUrl } = useCurrentUrl();

const linkClass =
    'rounded-md px-3 py-2 text-sm whitespace-nowrap text-text-muted transition hover:bg-surface-muted hover:text-text aria-[current=page]:bg-subtle aria-[current=page]:text-text';
</script>

<template>
    <NavigationMenu as="div" :viewport="false" class="max-w-none">
        <NavigationMenuList class="justify-start gap-1">
            <NavigationMenuItem
                v-for="entry in entries"
                :key="`${entry.label}:${entry.url ?? ''}`"
            >
                <template v-if="entry.children.length > 0">
                    <NavigationMenuTrigger
                        class="h-auto bg-transparent px-3 py-2 text-sm font-normal text-text-muted hover:bg-surface-muted hover:text-text focus:bg-surface-muted focus:text-text data-[state=open]:bg-subtle data-[state=open]:text-text data-[state=open]:hover:bg-subtle data-[state=open]:focus:bg-subtle"
                    >
                        {{ entry.label }}
                    </NavigationMenuTrigger>
                    <NavigationMenuContent
                        class="min-w-48 border-border bg-surface text-text"
                    >
                        <ul class="flex flex-col gap-1">
                            <li
                                v-for="child in entry.children"
                                :key="`${child.label}:${child.url ?? ''}`"
                            >
                                <NavigationMenuLink as-child>
                                    <component
                                        :is="child.external ? 'a' : Link"
                                        :href="child.url ?? '#'"
                                        :target="
                                            child.newTab ? '_blank' : undefined
                                        "
                                        :rel="
                                            child.newTab
                                                ? 'noopener noreferrer'
                                                : undefined
                                        "
                                        :aria-current="
                                            child.url && isCurrentUrl(child.url)
                                                ? 'page'
                                                : undefined
                                        "
                                        class="block rounded-md px-3 py-2 text-sm whitespace-nowrap text-text-muted transition hover:bg-surface-muted hover:text-text focus:bg-surface-muted focus:text-text aria-[current=page]:bg-subtle aria-[current=page]:text-text"
                                    >
                                        {{ child.label }}
                                    </component>
                                </NavigationMenuLink>
                            </li>
                        </ul>
                    </NavigationMenuContent>
                </template>
                <NavigationMenuLink v-else as-child>
                    <component
                        :is="entry.external ? 'a' : Link"
                        :href="entry.url ?? '#'"
                        :target="entry.newTab ? '_blank' : undefined"
                        :rel="entry.newTab ? 'noopener noreferrer' : undefined"
                        :aria-current="
                            entry.url && isCurrentUrl(entry.url)
                                ? 'page'
                                : undefined
                        "
                        :class="linkClass"
                    >
                        {{ entry.label }}
                    </component>
                </NavigationMenuLink>
            </NavigationMenuItem>
        </NavigationMenuList>
    </NavigationMenu>
</template>
