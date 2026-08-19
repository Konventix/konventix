<script setup lang="ts">
/**
 * Brand theme editor (ADR-0008, ADR-0029). Two maps now: `theme_tokens` lands
 * on `:root` and `theme_tokens_dark` on `.dark`, and the scheme switch below
 * chooses which one the grid is editing.
 *
 * The switch changes the defaults behind the inputs as well as the values in
 * them. That is the point of separate maps rather than one: a dark accent is
 * supposed to look wrong beside the light default, and judging it against the
 * light palette — which is what a single map forced — is how a brand ends up
 * with an unreadable dark mode.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import ThemeContrastReport from '@/components/admin/theme/ThemeContrastReport.vue';
import ThemePreview from '@/components/admin/theme/ThemePreview.vue';
import ThemeTokenGrid from '@/components/admin/theme/ThemeTokenGrid.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

const props = defineProps<{
    brand: Konventix.Platform.Data.BrandSummaryData;
    tokens: Record<string, string>;
    darkTokens: Record<string, string>;
    catalog: Konventix.Platform.Data.ThemeCatalogData;
}>();

type Scheme = 'light' | 'dark';

const scheme = ref<Scheme>('light');

// Only overridden tokens are keys in `form.tokens`; everything else falls
// back to the platform default shown as each input's placeholder. The form
// is the single draft state, so `form.isDirty` powers the guard (ADR-0038).
const form = useForm<{
    tokens: Record<string, string>;
    darkTokens: Record<string, string>;
}>({
    tokens: { ...props.tokens },
    darkTokens: { ...props.darkTokens },
});

useUnsavedChanges(() => form.isDirty);

const schemeDefaults = computed<Record<string, string>>(() => {
    const defaults: Record<string, string> = {};

    for (const group of props.catalog.groups) {
        for (const token of group.tokens) {
            defaults[token.name] =
                scheme.value === 'dark' ? token.dark : token.light;
        }
    }

    return defaults;
});

// The map currently being edited. Both live in the same form, so switching
// schemes never loses the other one's unsaved edits.
const overrides = computed<Record<string, string>>(() =>
    scheme.value === 'dark' ? form.darkTokens : form.tokens,
);

// What actually renders once overrides land on :root (or .dark) — used by
// both the preview pane and the contrast report, so they always agree, and
// both follow the scheme switch.
const effectiveTokens = computed<Record<string, string>>(() => ({
    ...schemeDefaults.value,
    ...overrides.value,
}));

// The save can be refused for either map, and the operator needs to be shown
// the message for the one that failed even if they are looking at the other.
const tokenError = computed<string | undefined>(
    () => form.errors.tokens ?? form.errors.darkTokens,
);

function updateOverrides(next: Record<string, string>): void {
    if (scheme.value === 'dark') {
        form.darkTokens = next;

        return;
    }

    form.tokens = next;
}

function submit(): void {
    form.put(`/admin/brands/${props.brand.id}/theme`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${brand.name} — Theme`" />

    <div class="flex flex-col gap-6">
        <div>
            <Link
                href="/admin/brands"
                class="text-sm text-text-muted hover:text-text"
            >
                ← Back to brands
            </Link>
            <div class="mt-2">
                <AdminPageHeader :title="`${brand.name} — Theme`" />
            </div>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
                Overrides apply for every visitor on {{ brand.name }}'s domains
                (ADR-0008). The light map lands on <code>:root</code> and the
                dark map on <code>.dark</code>; a token you leave alone keeps
                the platform default for that scheme.
            </p>
        </div>

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <div
                    role="tablist"
                    aria-label="Colour scheme"
                    class="inline-flex rounded-lg border border-border p-1"
                >
                    <button
                        v-for="option in ['light', 'dark'] as const"
                        :key="option"
                        type="button"
                        role="tab"
                        :aria-selected="scheme === option"
                        :class="[
                            'rounded-md px-3 py-1 text-sm capitalize transition',
                            scheme === option
                                ? 'bg-accent text-on-accent'
                                : 'text-text-muted hover:text-text',
                        ]"
                        @click="scheme = option"
                    >
                        {{ option }}
                    </button>
                </div>

                <ThemeTokenGrid
                    :groups="catalog.groups"
                    :model-value="overrides"
                    :scheme="scheme"
                    @update:model-value="updateOverrides"
                />
                <InputError :message="tokenError" />

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save changes' }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </div>

            <div class="space-y-6 lg:sticky lg:top-4 lg:self-start">
                <ThemePreview :tokens="effectiveTokens" />
                <ThemeContrastReport
                    :pairs="catalog.contrastPairs"
                    :effective-tokens="effectiveTokens"
                />
            </div>
        </form>
    </div>
</template>
