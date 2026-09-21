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
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    exportMethod as exportTheme,
    importMethod as importTheme,
    update as updateTheme,
} from '@/routes/admin/brands/theme';

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
    form.put(updateTheme(props.brand.id).url, {
        preserveScroll: true,
    });
}

// Export and import (Task 10 of the 2026-09-21 bugfix round). The export is
// a plain anchor, not an Inertia link: it is a file, and an Inertia visit
// would fetch it over XHR and show the JSON in the error modal. The import
// is its own form with its own error bag, so a refused file never shows up
// as an error under the token grid — and the other way round.
const importForm = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);

// The server refuses a file on `file` (not JSON), `format` (not a theme
// export), `tokens`/`darkTokens` (unknown name, contrast) or
// `tokens.<name>` (not a colour). The import control shows the first of
// those; a keyed list is the editor's job, not a file control's.
const importError = computed<string | undefined>(() => {
    const errors = importForm.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((name) => errors[name]);

    if (key === undefined) {
        return undefined;
    }

    return key === 'file' ? errors[key] : `${key}: ${errors[key]}`;
});

function onImportFileChange(event: Event): void {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

// An import replaces both maps, and the page shows them again through
// `form`. ADR-0038: a dirty draft is never overwritten silently — the
// operator either saves first or confirms losing it, exactly as the
// navigation guard would ask.
function submitImport(): void {
    if (
        form.isDirty &&
        !confirm(
            'You have unsaved changes. Importing a theme replaces them. Continue?',
        )
    ) {
        return;
    }

    importForm.post(importTheme(props.brand.id).url, {
        forceFormData: true,
        errorBag: 'themeImport',
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                tokens: { ...props.tokens },
                darkTokens: { ...props.darkTokens },
            });
            form.reset();
            importForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
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

                <section
                    aria-labelledby="theme-transfer-heading"
                    class="space-y-3 rounded-lg border border-border p-4"
                >
                    <h2 id="theme-transfer-heading" class="text-sm font-medium">
                        Export and import
                    </h2>
                    <p class="text-xs text-text-muted">
                        The export is a
                        <code>konventix.brand-theme/v1</code> file with both
                        maps, ready to import into another brand. An import
                        replaces both maps and is checked like a save.
                    </p>
                    <a
                        :href="exportTheme(brand.id).url"
                        download
                        class="inline-flex h-8 items-center rounded-md border border-border px-3 text-sm hover:bg-subtle"
                    >
                        Export
                    </a>
                    <div class="grid gap-2">
                        <Label for="theme-import-file">Theme file (JSON)</Label>
                        <input
                            id="theme-import-file"
                            ref="fileInput"
                            type="file"
                            accept="application/json,.json"
                            class="text-sm"
                            @change="onImportFileChange"
                        />
                        <InputError :message="importError" />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="importForm.processing || !importForm.file"
                        @click="submitImport"
                    >
                        {{ importForm.processing ? 'Importing…' : 'Import' }}
                    </Button>
                </section>
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
