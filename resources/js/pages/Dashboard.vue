<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <!-- TODO: replace with the real dashboard once a bounded context owns it. -->
        <div
            class="flex flex-1 items-center justify-center rounded-xl border border-border p-8 text-sm text-text-muted"
        >
            Nothing here yet.
        </div>
    </div>
</template>
