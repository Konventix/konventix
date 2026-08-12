<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CardsBlock from '@/components/blocks/CardsBlock.vue';
import CountdownBlock from '@/components/blocks/CountdownBlock.vue';
import EventHeroBlock from '@/components/blocks/EventHeroBlock.vue';
import HeroBlock from '@/components/blocks/HeroBlock.vue';
import TextBlock from '@/components/blocks/TextBlock.vue';
import PublicTopbar from '@/components/PublicTopbar.vue';

/**
 * Renders a brand static page from its resolved block list. The component map
 * is the frontend half of Platform's block registry; modules contributing
 * block types (e.g. Event's `event-hero`/`countdown`) extend this map.
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

const componentMap: Record<string, unknown> = {
    'blocks/TextBlock': TextBlock,
    'blocks/HeroBlock': HeroBlock,
    'blocks/CardsBlock': CardsBlock,
    'blocks/EventHeroBlock': EventHeroBlock,
    'blocks/CountdownBlock': CountdownBlock,
};
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
                    :is="componentMap[block.component]"
                    v-if="componentMap[block.component]"
                    v-bind="block.props"
                />
            </template>
        </main>
    </div>
</template>
