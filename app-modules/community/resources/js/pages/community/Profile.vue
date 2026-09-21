<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Heart, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { show as showProfile } from '@/routes/community/profile';
import {
    destroy as destroyComment,
    react,
    store as storeComment,
    unreact,
} from '@/routes/community/profile/comments';

/**
 * `u/{username}` — Community's first routed page (ADR-0051 §7, ADR-0052).
 *
 * Comments stay internal-scope under every visibility (ADR-0052 §2): the
 * server computes `comments`/`canComment` ONLY when the viewer passed
 * `resolveProfileForMemberOrFail`'s check, so a guest or non-member on a
 * `public` profile gets `comments: null` and no comments section renders at
 * all — the write endpoints 404 them the same way (Task 9).
 *
 * A comment author's handle links to their own page (ADR-0065): this page
 * exists only where Community is active, so the route is always there —
 * whether it answers is the profile's own visibility rule (ADR-0052).
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

type CommentProps = {
    id: string;
    body: string;
    authorName: string;
    authorUsername: string | null;
    postedAt: string;
    isMine: boolean;
    canDelete: boolean;
    reactionCount: number;
    hasReacted: boolean;
};

type Props = {
    profile: ProfileProps;
    viewerIsMember: boolean;
    comments: CommentProps[] | null;
    canComment: boolean;
};

const props = defineProps<Props>();

const { getInitials } = useInitials();

const commentForm = useForm({ body: '' });

function formatCommentDate(value: string): string {
    return formatDateTime(value, { dateStyle: 'long', timeStyle: 'short' });
}

function submitComment(): void {
    commentForm.post(storeComment({ username: props.profile.username }).url, {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
    });
}

function deleteComment(comment: CommentProps): void {
    router.delete(
        destroyComment({
            username: props.profile.username,
            comment: comment.id,
        }).url,
        { preserveScroll: true },
    );
}

function toggleReaction(comment: CommentProps): void {
    const params = { username: props.profile.username, comment: comment.id };

    if (comment.hasReacted) {
        router.delete(unreact(params).url, { preserveScroll: true });
    } else {
        router.post(react(params).url, {}, { preserveScroll: true });
    }
}

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

// A calendar date (`member_since->toDateString()`), not an instant: read as
// one it would be the day before for every reader west of Greenwich.
const memberSinceLabel = computed(() =>
    formatCalendarDate(props.profile.memberSince),
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

        <section
            v-if="props.comments !== null"
            class="flex flex-col gap-6 border-t border-border pt-8"
        >
            <h2 class="text-lg font-medium">
                Comments ({{ props.comments.length }})
            </h2>

            <p
                v-if="props.comments.length === 0"
                class="text-sm text-text-muted"
            >
                Nothing here yet.
            </p>

            <div
                v-for="comment in props.comments"
                :key="comment.id"
                class="flex flex-col gap-1 rounded-lg border border-border p-4"
            >
                <div
                    class="flex items-center justify-between gap-2 text-xs text-text-muted"
                >
                    <span>
                        <span class="font-medium text-text">
                            {{ comment.authorName }}
                        </span>
                        <span v-if="comment.authorUsername">
                            ·
                            <Link
                                :href="showProfile(comment.authorUsername).url"
                                class="hover:text-accent"
                            >
                                @{{ comment.authorUsername }}
                            </Link>
                        </span>
                        · {{ formatCommentDate(comment.postedAt) }}
                        <span v-if="comment.isMine">· you</span>
                    </span>
                    <span class="flex items-center gap-3">
                        <button
                            v-if="!comment.isMine"
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-accent"
                            :class="comment.hasReacted && 'text-accent'"
                            :aria-pressed="comment.hasReacted"
                            :aria-label="`${comment.hasReacted ? 'Unlike' : 'Like'} (${comment.reactionCount})`"
                            @click="toggleReaction(comment)"
                        >
                            <Heart
                                class="size-3.5"
                                :class="comment.hasReacted && 'fill-current'"
                            />
                            {{ comment.reactionCount }}
                        </button>
                        <span
                            v-else-if="comment.reactionCount > 0"
                            class="inline-flex items-center gap-1"
                        >
                            <Heart class="size-3.5" />
                            {{ comment.reactionCount }}
                        </span>
                        <button
                            v-if="comment.canDelete"
                            type="button"
                            class="hover:text-danger"
                            :aria-label="`Delete comment by ${comment.authorName}`"
                            @click="deleteComment(comment)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </div>
                <p class="text-sm whitespace-pre-line">
                    {{ comment.body }}
                </p>
            </div>

            <form
                v-if="props.canComment"
                class="flex flex-col gap-2"
                @submit.prevent="submitComment"
            >
                <label for="comment-body" class="text-sm font-medium">
                    Add a comment
                </label>
                <textarea
                    id="comment-body"
                    v-model="commentForm.body"
                    rows="4"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                />
                <InputError :message="commentForm.errors.body" />
                <div>
                    <Button type="submit" :disabled="commentForm.processing">
                        Post comment
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
