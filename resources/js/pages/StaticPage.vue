<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicTopbar from '@/components/PublicTopbar.vue';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';

/**
 * Renders a brand static page from its resolved block list. The component map
 * resolves through the registered-component convention
 * (`<module>/<path>` — see lib/moduleComponents.ts), so a module
 * contributing a block type ships its own Vue component and no host-side
 * map exists to edit (ADR-0003).
 */
type RenderableBlock = {
    key: string;
    component: string;
    props: Record<string, unknown>;
};

const props = defineProps<{
    title: string;
    blocks: RenderableBlock[];
}>();

</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen flex-col bg-surface text-text">
        <PublicTopbar :name="$page.props.name" />

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-10 p-6 lg:p-8"
        >
            <!--
                A hero block already carries a prominent heading, so the page
                title is not repeated above it — plain pages (imprint, about)
                have no hero and keep the title as their only heading.
            -->
            <h1
                v-if="props.blocks[0]?.key !== 'hero'"
                class="text-3xl font-semibold text-text"
            >
                {{ title }}
            </h1>

            <template
                v-for="(block, index) in props.blocks"
                :key="`${block.key}-${index}`"
            >
                <component
                    :is="resolveRegisteredComponent(block.component)"
                    v-if="resolveRegisteredComponent(block.component)"
                    v-bind="block.props"
                />
            </template>
        </main>
    </div>
</template>
