<script setup lang="ts">
/**
 * Platform's contribution to the `admin.dashboard` slot: the packages'
 * own dashboards, already filtered server-side by capability. Plain
 * anchors, not Inertia links — these pages are not Inertia pages.
 */
defineProps<{
    links: { label: string; url: string; description: string }[];
}>();
</script>

<template>
    <section
        v-if="links.length > 0"
        class="rounded-xl border border-border p-4"
        aria-labelledby="diagnostics-heading"
    >
        <h2 id="diagnostics-heading" class="text-sm font-semibold text-text">
            Diagnostics
        </h2>
        <ul class="mt-3 flex flex-col gap-2">
            <li v-for="link in links" :key="link.url">
                <a
                    :href="link.url"
                    class="text-sm font-medium text-accent underline-offset-4 hover:underline"
                >
                    {{ link.label }}
                </a>
                <p class="text-xs text-text-muted">{{ link.description }}</p>
            </li>
        </ul>
    </section>
</template>
