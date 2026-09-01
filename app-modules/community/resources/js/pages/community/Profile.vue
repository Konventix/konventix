<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * `u/{username}` — Community's first routed page (ADR-0051 §7, ADR-0052).
 * `viewerIsMember` rides alongside `profile` unused here on purpose: it is
 * exactly ADR-0052 §2's internal-scope comment-eligibility check, computed
 * once by `ResolvesProfiles` so Task 9's comments surface can build on it
 * without re-deriving it.
 */
type SocialLinks = Record<string, string>;

type ProfileProps = {
    username: string;
    displayName: string;
    tagline: string | null;
    statusEmoji: string | null;
    socialLinks: SocialLinks;
    avatarUrl: string | null;
    bannerUrl: string | null;
    memberSince: string;
    isSelf: boolean;
};

type Props = {
    profile: ProfileProps;
    viewerIsMember: boolean;
};

const props = defineProps<Props>();

const { getInitials } = useInitials();

/**
 * Where each social key resolves to on the open web. `discord` has no
 * canonical profile URL, so it renders as copyable text instead of a link
 * (brief's own note). Only keys this map recognizes render at all — "filling
 * a field is choosing to show it" applies to the fields Community's settings
 * form actually offers, not to arbitrary keys a payload might carry.
 */
const socialHref: Record<string, (value: string) => string | null> = {
    steam: (v) => `https://steamcommunity.com/id/${v}`,
    discord: () => null,
    twitch: (v) => `https://twitch.tv/${v}`,
    youtube: (v) => `https://youtube.com/@${v.replace(/^@/, '')}`,
    x: (v) => `https://x.com/${v}`,
    instagram: (v) => `https://instagram.com/${v}`,
    website: (v) => v,
};

const socialEntries = computed(() =>
    Object.entries(props.profile.socialLinks)
        .filter(([key]) => key in socialHref)
        .map(([key, value]) => ({
            key,
            value,
            href: socialHref[key](value),
        })),
);

const memberSinceLabel = computed(() =>
    new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
        new Date(props.profile.memberSince),
    ),
);
</script>

<template>
    <Head :title="profile.displayName" />

    <div
        class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <div
            class="h-32 w-full rounded-lg bg-surface-muted bg-cover bg-center"
            :style="
                profile.bannerUrl
                    ? { backgroundImage: `url(${profile.bannerUrl})` }
                    : undefined
            "
        />

        <div
            class="-mt-14 flex flex-col gap-4 px-2 sm:flex-row sm:items-end sm:gap-6"
        >
            <Avatar class="size-24 border-4 border-surface">
                <AvatarImage
                    v-if="profile.avatarUrl"
                    :src="profile.avatarUrl"
                    :alt="profile.displayName"
                />
                <AvatarFallback class="text-2xl text-text">
                    {{ getInitials(profile.displayName) }}
                </AvatarFallback>
            </Avatar>

            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-2xl font-semibold">
                    {{ profile.displayName }}
                    <span v-if="profile.statusEmoji">{{
                        profile.statusEmoji
                    }}</span>
                </h1>
                <p class="text-text-muted">@{{ profile.username }}</p>
                <p class="text-sm text-text-muted">
                    Member since {{ memberSinceLabel }}
                </p>
            </div>
        </div>

        <p v-if="profile.tagline" class="max-w-prose text-text">
            {{ profile.tagline }}
        </p>

        <section v-if="socialEntries.length > 0" class="flex flex-col gap-2">
            <h2 class="text-lg font-medium">Elsewhere</h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="entry in socialEntries"
                    :key="entry.key"
                    class="flex items-center gap-2 text-sm"
                >
                    <span class="text-text-muted capitalize">{{
                        entry.key
                    }}</span>
                    <a
                        v-if="entry.href"
                        :href="entry.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-accent hover:underline"
                    >
                        {{ entry.value }}
                    </a>
                    <span v-else class="text-text">{{ entry.value }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
