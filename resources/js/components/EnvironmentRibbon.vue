<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The corner ribbon every non-production instance wears (GitLab #68): the
 * environment's label, with the release underneath it.
 *
 * Fixed to the top-right corner of the viewport and fully click-through —
 * `pointer-events: none` on every element of it, because the brand topbar
 * keeps its account menu and cart in exactly that corner. That is also why
 * the release is a second line rather than a hover tooltip: a tooltip needs
 * pointer events, and the ribbon may have none. `title` is still set for
 * assistive technology, and the `aside` carries an accessible name.
 *
 * Absent on production, where the shared prop is null: the decision is the
 * server's (`DeploymentEnvironment`), never this component's.
 */
const page = usePage();

const environment = computed(() => page.props.environment ?? null);
</script>

<template>
    <aside
        v-if="environment"
        class="pointer-events-none fixed top-0 right-0 z-50 h-32 w-32 overflow-hidden print:hidden"
        :aria-label="`${environment.label} instance, release ${environment.release}`"
        :title="`${environment.label} · ${environment.release}`"
        data-environment-ribbon
    >
        <div
            class="absolute top-6 -right-11 w-48 rotate-45 py-1 text-center text-danger-contrast shadow-md"
            :style="{ backgroundColor: environment.color }"
        >
            <span class="block text-xs font-bold tracking-widest uppercase">
                {{ environment.label }}
            </span>
            <span class="block text-[10px] leading-tight opacity-90">
                {{ environment.release }}
            </span>
        </div>
    </aside>
</template>
