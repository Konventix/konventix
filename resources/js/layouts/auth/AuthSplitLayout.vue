<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import OperatorPresenceNotice from '@/components/OperatorPresenceNotice.vue';
import { home } from '@/routes';

const page = usePage();
// The brand this host serves, not the application's own name: an auth page
// says whose site it is (#241, ADR-0054 point 5).
const name = computed(() => page.props.brand.name);

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-surface-muted p-10 text-text-inverted lg:flex lg:border-r lg:border-border"
        >
            <div class="absolute inset-0 bg-surface-inverted" />
            <Link
                :href="home()"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <AppLogo :show-name="false" class="mr-2" />
                {{ name }}
            </Link>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />

                <OperatorPresenceNotice />
            </div>
        </div>
    </div>
</template>
