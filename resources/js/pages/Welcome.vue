<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import PublicTopbar from '@/components/PublicTopbar.vue';

/**
 * Fallback landing page, rendered by the `/` route (routes/web.php) when the
 * current brand has no published `home`-slug StaticPage yet. Kept minimal on
 * purpose — a brand that wants a real landing experience publishes a `home`
 * page built from blocks instead.
 */
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-surface text-text">
        <PublicTopbar :name="$page.props.name" />

        <main
            class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center lg:p-8"
        >
            <!-- TODO(brand): swap the placeholder mark for the Konventix logo. -->
            <AppLogoIcon class="size-10 fill-current text-text" />
            <h1 class="text-2xl font-semibold">{{ $page.props.name }}</h1>
            <p class="max-w-prose text-sm text-text-muted">
                Event-agnostic event management platform.
            </p>
        </main>
    </div>
</template>
