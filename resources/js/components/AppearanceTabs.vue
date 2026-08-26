<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { useCookieSettingsDialog } from '@/composables/useCookieSettingsDialog';

const { appearance, updateAppearance } = useAppearance();
const { open } = useCookieSettingsDialog();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div>
        <div class="inline-flex gap-1 rounded-lg bg-surface-muted p-1">
            <button
                v-for="{ value, Icon, label } in tabs"
                :key="value"
                @click="updateAppearance(value)"
                :class="[
                    'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                    appearance === value
                        ? 'bg-surface text-text shadow-xs'
                        : 'text-text-muted hover:bg-surface/60 hover:text-text',
                ]"
            >
                <component :is="Icon" class="-ml-1 h-4 w-4" />
                <span class="ml-1.5 text-sm">{{ label }}</span>
            </button>
        </div>

        <p class="mt-2 text-xs text-text-muted">
            Your choice is kept in a cookie for a year so it survives closing
            the browser — you can turn that off under
            <button
                type="button"
                class="underline underline-offset-4 hover:text-text"
                @click="open()"
            >
                Cookie settings
            </button>
            .
        </p>
    </div>
</template>
