<script setup lang="ts">
import type { Component } from 'vue';
import { Card, CardContent } from '@/components/ui/card';

/**
 * LanCore-style stat tile (icon top-right, big number, subtext, optional
 * progress bar), rebuilt on Konventix's Card primitive with semantic design
 * tokens only — no raw palette utilities (.ai/rules/resources.md, ADR-0008).
 */
defineProps<{
    title: string;
    value: string | number;
    subtext?: string;
    progress?: number;
    icon?: Component;
}>();
</script>

<template>
    <Card>
        <CardContent class="flex flex-col gap-2">
            <div class="flex items-start justify-between gap-2">
                <div class="text-sm font-medium text-text-muted">
                    {{ title }}
                </div>
                <component
                    :is="icon"
                    v-if="icon"
                    class="size-4 shrink-0 text-text-muted"
                />
            </div>
            <div class="text-3xl font-semibold text-text tabular-nums">
                {{ value }}
            </div>
            <div v-if="subtext" class="text-xs text-text-muted">
                {{ subtext }}
            </div>
            <div
                v-if="progress !== undefined"
                class="mt-1 h-2 w-full overflow-hidden rounded-full bg-surface-muted"
            >
                <div
                    class="h-full bg-accent transition-all"
                    :style="{
                        width: `${Math.min(100, Math.max(0, progress))}%`,
                    }"
                />
            </div>
        </CardContent>
    </Card>
</template>
