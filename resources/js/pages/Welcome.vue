<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';

/**
 * Fallback landing page, rendered by the `/` route (routes/web.php) when the
 * current brand has no published `home`-slug StaticPage yet. Kept minimal on
 * purpose — a brand that wants a real landing experience publishes a `home`
 * page built from blocks instead.
 */
</script>

<template>
    <Head :title="$t('Welcome')" />

    <div
        class="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-6 text-center text-text lg:p-8"
    >
        <AppLogo :show-name="false" />
        <h1 class="text-2xl font-semibold">{{ $page.props.name }}</h1>
        <p class="max-w-prose text-sm text-text-muted">
            {{ $t('Event-agnostic event management platform.') }}
        </p>
    </div>
</template>
