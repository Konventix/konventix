<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { settingsSections } from '@/lib/settingsSections';
import { toUrl } from '@/lib/utils';
import { index as settingsIndex } from '@/routes/settings';
import type { BreadcrumbItem } from '@/types';

/**
 * `breadcrumbs` are the page's own declaration
 * (`defineOptions({ layout: { breadcrumbs } })`), merged in by Inertia's
 * default-layout hook (see `layoutFor()` in app.ts). Without a prop of that
 * name here the declaration was dropped on the floor, which is why no
 * settings page showed a trail. The hub is prepended so every trail starts
 * at "Settings", the one page that is not wrapped in this layout.
 */
const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

// English keys, like the page's own: `Breadcrumbs` translates every title.
const trail = computed<BreadcrumbItem[]>(() => [
    { title: 'Settings', href: settingsIndex() },
    ...props.breadcrumbs,
]);

// The same list the hub page at /settings renders (lib/settingsSections.ts);
// its titles are catalog keys, translated where they render.
const sidebarNavItems = settingsSections;

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto w-full max-w-5xl px-4 py-6">
        <Breadcrumbs :breadcrumbs="trail" class="mb-4" />

        <Heading
            :title="$t('Settings')"
            :description="$t('Manage your profile and account settings')"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="$t('Settings')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ $t(item.title) }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
