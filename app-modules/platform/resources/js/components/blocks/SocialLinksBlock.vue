<script setup lang="ts">
import { Globe } from '@lucide/vue';

type SocialLink = {
    network: string;
    url: string;
    label: string | null;
};

defineProps<{
    links: SocialLink[];
}>();

/**
 * One short, recognisable path per network (24x24 viewBox) — hand-drawn
 * marks rather than pixel-exact logos, so they stay small and dependency
 * free. `website` renders lucide's `Globe` instead, since it already ships
 * one.
 */
const iconPaths: Record<string, string> = {
    github: 'M12 2C6.48 2 2 6.58 2 12.25c0 4.53 2.87 8.37 6.84 9.73.5.1.68-.22.68-.5v-1.75c-2.78.62-3.37-1.35-3.37-1.35-.46-1.18-1.11-1.5-1.11-1.5-.9-.63.07-.62.07-.62 1 .07 1.53 1.05 1.53 1.05.9 1.56 2.36 1.11 2.94.85.09-.66.35-1.11.63-1.37-2.22-.26-4.56-1.14-4.56-5.06 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.71 0 0 .84-.27 2.75 1.05a9.4 9.4 0 0 1 5 0c1.91-1.32 2.75-1.05 2.75-1.05.55 1.41.2 2.45.1 2.71.64.72 1.03 1.63 1.03 2.75 0 3.93-2.34 4.8-4.57 5.05.36.32.68.94.68 1.9v2.82c0 .28.18.6.69.5A10.02 10.02 0 0 0 22 12.25C22 6.58 17.52 2 12 2Z',
    x: 'M18.9 3H21l-7.5 8.57L22 21h-6.9l-5.4-6.6L3.7 21H1.6l8.02-9.17L2 3h7.1l4.9 6.02L18.9 3zm-1.2 16h1.9L7.4 5H5.4l12.3 14z',
    facebook:
        'M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.4h-1.2c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z',
    instagram:
        'M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm5 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm0 2a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm4.8-3.3a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z',
    youtube:
        'M23 12s0-3.2-.4-4.7a3 3 0 0 0-2.1-2.1C18.9 4.8 12 4.8 12 4.8s-6.9 0-8.5.4A3 3 0 0 0 1.4 7.3C1 8.8 1 12 1 12s0 3.2.4 4.7a3 3 0 0 0 2.1 2.1c1.6.4 8.5.4 8.5.4s6.9 0 8.5-.4a3 3 0 0 0 2.1-2.1c.4-1.5.4-4.7.4-4.7zM9.8 15.5v-7l6 3.5-6 3.5z',
    twitch: 'M4 2 2 6v14h6v2h3l2-2h4l4-4V2H4zm14 11-3 3h-4l-2 2v-2H5V4h13v9zm-2-6h-2v5h2V7zm-5 0H9v5h2V7z',
    discord:
        'M20 4.4A18 18 0 0 0 15.6 3l-.3.6a13 13 0 0 1 3.7 1.4c-4.5-2-9.6-2-14 0A13 13 0 0 1 8.7 3.6L8.4 3A18 18 0 0 0 4 4.4C1.4 8.2.7 12 1 15.6c1.9 1.4 3.7 2.2 5.5 2.8l.9-1.5a11 11 0 0 1-1.8-.8l.4-.3c3.4 1.6 7.2 1.6 10.6 0l.4.3c-.6.3-1.2.6-1.8.8l.9 1.5c1.8-.6 3.6-1.4 5.5-2.8.4-4.2-.6-8-2.6-11.2zM9 13.6c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.9.9 1.8 2c0 1.1-.8 2-1.8 2zm6 0c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.9.9 1.8 2c0 1.1-.8 2-1.8 2z',
    mastodon:
        'M12 2C7 2 4 3.3 4 3.3v9.4c0 4.6 3.3 7.1 4.9 7.9.4.2.9 0 .9-.5v-2.4c-2-.4-3-1.7-3-3.7V7.6c0-.4.3-.7.7-.7h.9c.4 0 .7.3.7.7v6c0 1 .6 1.6 1.5 1.6.8 0 1.4-.5 1.5-1.4V7.6c0-.4.3-.7.7-.7h.9c.4 0 .7.3.7.7v6.3c.1.9.7 1.4 1.5 1.4.9 0 1.5-.6 1.5-1.6v-6c0-.4.3-.7.7-.7h.9c.4 0 .7.3.7.7v6.4c0 2-.9 3.3-3 3.7v2.4c0 .5.5.7.9.5 1.6-.8 4.9-3.3 4.9-7.9V3.3S17 2 12 2z',
    linkedin:
        'M20 2H4a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM8.3 18.5H5.6V9.8h2.7v8.7zM7 8.6a1.6 1.6 0 1 1 0-3.2 1.6 1.6 0 0 1 0 3.2zm11.5 9.9h-2.7v-4.4c0-1.1 0-2.4-1.5-2.4s-1.7 1.1-1.7 2.3v4.5H10V9.8h2.6v1.2h.1c.4-.7 1.3-1.5 2.7-1.5 2.9 0 3.4 1.9 3.4 4.3v4.7z',
};

/** `x` -> "X"; every other key gets its first letter capitalised. */
function displayName(network: string): string {
    return network === 'x'
        ? 'X'
        : network.charAt(0).toUpperCase() + network.slice(1);
}
</script>

<template>
    <ul class="flex flex-wrap gap-3">
        <li v-for="(link, index) in links" :key="`${link.network}-${index}`">
            <a
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 text-sm hover:text-text"
                :aria-label="
                    !link.label ? displayName(link.network) : undefined
                "
            >
                <Globe
                    v-if="link.network === 'website'"
                    class="size-4"
                    aria-hidden="true"
                />
                <svg
                    v-else
                    class="size-4"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path :d="iconPaths[link.network] ?? ''" />
                </svg>
                {{ link.label || displayName(link.network) }}
            </a>
        </li>
    </ul>
</template>
