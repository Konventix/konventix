<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicTopbar from '@/components/PublicTopbar.vue';

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
        version: string;
        effectiveAt: string | null;
        requiresConsent: boolean;
        html: string;
    };
};

const props = defineProps<Props>();

defineOptions({ layout: null });

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'long' });

function formatDate(value: string | null): string {
    return value ? dateFormat.format(new Date(value)) : '';
}
</script>

<template>
    <Head :title="props.policy.name" />

    <div class="flex min-h-screen flex-col bg-surface text-text">
        <PublicTopbar :name="$page.props.name" />

        <main
            class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-6 lg:p-8"
        >
            <header class="flex flex-col gap-2">
                <h1 class="text-3xl font-semibold">{{ props.policy.name }}</h1>
                <p class="text-sm text-text-muted">
                    Version {{ props.policy.version }}
                    <template v-if="props.policy.effectiveAt">
                        · in effect since
                        {{ formatDate(props.policy.effectiveAt) }}
                    </template>
                </p>
            </header>

            <!-- eslint-disable-next-line vue/no-v-html -->
            <article class="policy-prose flex flex-col gap-4" v-html="props.policy.html" />
        </main>
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
