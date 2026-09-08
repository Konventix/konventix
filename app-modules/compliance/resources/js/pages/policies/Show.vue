<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/datetime';

/**
 * One policy's currently published text, on the brand's own domain.
 *
 * `v-html` is deliberate here and nowhere else: the HTML comes from
 * Compliance's `PolicyMarkdown`, whose CommonMark environment strips raw
 * HTML from the source and refuses unsafe link targets, so an operator
 * cannot paste a script into a legal text. A privacy policy without
 * headings, lists and links is not readable, which is why this page and
 * Content's plain-text entry bodies answer the question differently.
 */
type Props = {
    policy: {
        name: string;
        slug: string;
        /**
         * 'operator' when this is the operator's own text, inherited by
         * every brand that has not published its own (GitLab #76).
         */
        scope: 'operator' | 'brand';
        version: string;
        /**
         * An ISO 8601 instant with its offset, not a calendar day: a
         * version dated 00:30 in Berlin is 22:30Z the day before, and this
         * page used to print the day before for everyone (B5).
         */
        effectiveAt: string | null;
        requiresConsent: boolean;
        html: string;
    };
};

const props = defineProps<Props>();
</script>

<template>
    <Head :title="props.policy.name" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <header class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">{{ props.policy.name }}</h1>
            <p class="text-sm text-text-muted">
                Version {{ props.policy.version }}
                <template v-if="props.policy.effectiveAt">
                    · in effect since
                    <time :datetime="props.policy.effectiveAt">{{
                        formatDateTime(props.policy.effectiveAt, {
                            dateStyle: 'long',
                            timeStyle: undefined,
                        })
                    }}</time>
                </template>
                <template v-if="props.policy.scope === 'operator'">
                    · published by the operator for all of its brands
                </template>
            </p>
        </header>

        <!-- eslint-disable-next-line vue/no-v-html -->
        <article
            class="policy-prose flex flex-col gap-4"
            v-html="props.policy.html"
        />
    </div>
</template>

<style scoped>
/*
 * Rendered markup, so it cannot carry utility classes — the few element
 * rules the document actually uses, on semantic tokens only (ADR-0008).
 */
.policy-prose :deep(h2) {
    font-size: 1.25rem;
    font-weight: 600;
    margin-top: 1rem;
}

.policy-prose :deep(h3) {
    font-size: 1.05rem;
    font-weight: 600;
}

.policy-prose :deep(ul),
.policy-prose :deep(ol) {
    padding-left: 1.5rem;
    list-style: revert;
}

.policy-prose :deep(a) {
    color: var(--accent);
    text-decoration: underline;
}

.policy-prose :deep(table) {
    border-collapse: collapse;
}

.policy-prose :deep(th),
.policy-prose :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.75rem;
    text-align: left;
}
</style>
