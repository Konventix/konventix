<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Flag, Heart } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { index as newsIndex } from '@/routes/content';
import {
    react,
    report,
    store as storeComment,
    unreact,
} from '@/routes/content/comments';

/**
 * One entry and its approved comments.
 *
 * The body renders as plain text with preserved line breaks — deliberately
 * not `v-html`. Entries are written in the operator console by trusted
 * editors today, but an XSS sink on every brand's public pages is not the
 * place to rely on that; rich text arrives with a sanitizer, not before
 * (`TODO.md`).
 *
 * People are handles (ADR-0065): the byline and each comment author print
 * `@username`, linked to the profile only when the server sent a URL —
 * Community is active for this brand — and "Participant" for an account
 * the read model has not seen. The display name is never in the props.
 */
type Props = {
    entry: Konventix.Content.Data.PublicEntryData;
    comments: Konventix.Content.Data.PublicCommentData[];
    canComment: boolean;
    canReact: boolean;
};

const props = defineProps<Props>();

const form = useForm({ body: '' });

function formatDate(value: string | null): string {
    return formatDateTime(value, { dateStyle: 'long', timeStyle: 'short' });
}

function submit(): void {
    form.post(storeComment(props.entry.slug).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function reportComment(commentId: string): void {
    router.post(
        report({ slug: props.entry.slug, comment: commentId }).url,
        {},
        { preserveScroll: true },
    );
}

function toggleReaction(
    comment: Konventix.Content.Data.PublicCommentData,
): void {
    const params = { slug: props.entry.slug, comment: comment.id };

    if (comment.hasReacted) {
        router.delete(unreact(params).url, { preserveScroll: true });
    } else {
        router.post(react(params).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="props.entry.title" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6 text-text lg:p-8"
    >
        <Link
            :href="newsIndex().url"
            class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-text"
        >
            <ArrowLeft class="size-4" />
            All news
        </Link>

        <article class="flex flex-col gap-4">
            <div
                class="flex items-center gap-2 text-xs tracking-wide text-text-muted uppercase"
            >
                <span
                    v-if="props.entry.urgency === 'urgent'"
                    class="rounded-full bg-danger/15 px-2 py-0.5 text-danger"
                >
                    Urgent
                </span>
                <time :datetime="props.entry.publishedAt ?? undefined">
                    {{ formatDate(props.entry.publishedAt) }}
                </time>
                <span v-if="props.entry.authorUsername">
                    ·
                    <Link
                        v-if="props.entry.authorProfileUrl"
                        :href="props.entry.authorProfileUrl"
                        class="hover:text-accent"
                    >
                        @{{ props.entry.authorUsername }}
                    </Link>
                    <template v-else
                        >@{{ props.entry.authorUsername }}</template
                    >
                </span>
            </div>

            <h1 class="text-3xl font-semibold">{{ props.entry.title }}</h1>

            <p v-if="props.entry.teaser" class="text-lg text-text-muted">
                {{ props.entry.teaser }}
            </p>

            <div
                v-if="props.entry.body"
                class="text-base leading-relaxed whitespace-pre-line"
            >
                {{ props.entry.body }}
            </div>
        </article>

        <section
            v-if="props.entry.commentsEnabled"
            class="flex flex-col gap-6 border-t border-border pt-8"
        >
            <h2 class="text-xl font-semibold">
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
                        <Link
                            v-if="comment.authorProfileUrl"
                            :href="comment.authorProfileUrl"
                            class="font-medium text-text hover:text-accent"
                        >
                            @{{ comment.authorUsername }}
                        </Link>
                        <span v-else class="font-medium text-text">
                            {{
                                comment.authorUsername
                                    ? `@${comment.authorUsername}`
                                    : 'Participant'
                            }}
                        </span>
                        · {{ formatDate(comment.postedAt) }}
                        <span v-if="comment.isMine">· you</span>
                    </span>
                    <span class="flex items-center gap-3">
                        <button
                            v-if="props.canReact && !comment.isMine"
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
                            v-if="props.canComment && !comment.isMine"
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-danger"
                            @click="reportComment(comment.id)"
                        >
                            <Flag class="size-3.5" />
                            Report
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
                @submit.prevent="submit"
            >
                <label for="comment-body" class="text-sm font-medium">
                    Add a comment
                </label>
                <textarea
                    id="comment-body"
                    v-model="form.body"
                    rows="4"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                />
                <InputError :message="form.errors.body" />
                <div>
                    <Button type="submit" :disabled="form.processing">
                        Post comment
                    </Button>
                </div>
            </form>

            <p v-else class="text-sm text-text-muted">
                Log in to join the conversation.
            </p>
        </section>
    </div>
</template>
