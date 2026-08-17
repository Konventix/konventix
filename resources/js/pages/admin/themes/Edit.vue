<script setup lang="ts">
/**
 * Brand theme editor (ADR-0008, ADR-0029). `Brand.theme_tokens` is a single
 * map applied to `:root` today, so this page only edits one palette — a
 * `.dark` override is explicit follow-up work, not something this page
 * pretends to support with a tab that silently does nothing.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
    catalog: Konventix.Platform.Data.ThemeCatalogData;
}>();

// Only overridden tokens are keys in `form.tokens`; everything else falls
// back to the platform default shown as each input's placeholder. The form
// is the single draft state, so `form.isDirty` powers the guard (ADR-0038).
const form = useForm<{ tokens: Record<string, string> }>({
    tokens: { ...props.tokens },
});

useUnsavedChanges(() => form.isDirty);

const lightDefaults = computed<Record<string, string>>(() => {
    const defaults: Record<string, string> = {};

    for (const group of props.catalog.groups) {
        for (const token of group.tokens) {
            defaults[token.name] = token.light;
        }
    }

    return defaults;
});

// What actually renders once overrides land on :root — used by both the
// preview pane and the contrast report, so they always agree.
const effectiveTokens = computed<Record<string, string>>(() => ({
    ...lightDefaults.value,
    ...form.tokens,
}));

function updateOverrides(next: Record<string, string>): void {
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
                Overrides apply to <code>:root</code> for every visitor on
                {{ brand.name }}'s domains (ADR-0008). Dark-mode overrides are a
                follow-up — this editor writes a single token map, so
                <code>.dark</code> keeps using the platform default until that
                lands.
            </p>
        </div>

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <ThemeTokenGrid
                    :groups="catalog.groups"
                    :model-value="form.tokens"
                    @update:model-value="updateOverrides"
                />
                <InputError :message="form.errors.tokens" />

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
