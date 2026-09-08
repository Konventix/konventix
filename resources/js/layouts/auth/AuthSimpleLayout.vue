<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import EnvironmentRibbon from '@/components/EnvironmentRibbon.vue';
import OperatorPresenceNotice from '@/components/OperatorPresenceNotice.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-surface p-6 md:p-10"
    >
        <EnvironmentRibbon />
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <!--
                            The brand's own mark, not the bundled Konventix
                            one: an auth page has to show whose site this is
                            beside whose account it is (#241, ADR-0054 point
                            5). `AppLogo` falls back to the bundled icon for a
                            brand that has uploaded nothing, and the name is
                            already in the notice below, so it is hidden here.
                        -->
                        <AppLogo :show-name="false" />
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />

                <OperatorPresenceNotice />
            </div>
        </div>
    </div>
</template>
