<script setup lang="ts">
import {
    Calendar,
    Clock,
    MapPin,
    Megaphone,
    Sparkles,
    Ticket,
    Trophy,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

type Card = {
    title: string;
    body: string;
    icon: string | null;
};

defineProps<{
    cards: Card[];
}>();

/**
 * A fixed, kebab-case icon map — an unknown or missing `icon` key simply
 * renders the card without an icon rather than failing the page.
 */
const iconMap: Record<string, Component> = {
    calendar: Calendar,
    clock: Clock,
    'map-pin': MapPin,
    megaphone: Megaphone,
    sparkles: Sparkles,
    ticket: Ticket,
    trophy: Trophy,
    users: Users,
};

function resolveIcon(icon: string | null): Component | null {
    return icon ? (iconMap[icon] ?? null) : null;
}
</script>

<template>
    <section class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div
            v-for="(card, index) in cards"
            :key="index"
            class="flex flex-col gap-3 rounded-lg border border-border bg-surface-raised p-5"
        >
            <component
                :is="resolveIcon(card.icon)"
                v-if="resolveIcon(card.icon)"
                class="size-5 text-accent"
            />
            <h3 class="font-semibold text-text">{{ card.title }}</h3>
            <p class="text-sm text-text-muted">{{ card.body }}</p>
        </div>
    </section>
</template>
