<script setup lang="ts">
/**
 * Brand theme editor (ADR-0008, ADR-0029). `Brand.theme_tokens` is a single
 * map applied to `:root` today, so this page only edits one palette — a
 * `.dark` override is explicit follow-up work, not something this page
 * pretends to support with a tab that silently does nothing.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ThemeContrastReport from '@/components/admin/theme/ThemeContrastReport.vue';
import ThemePreview from '@/components/admin/theme/ThemePreview.vue';
import ThemeTokenGrid from '@/components/admin/theme/ThemeTokenGrid.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    brand: Konventix.Platform.Data.BrandSummaryData;
    tokens: Record<string, string>;
    catalog: Konventix.Platform.Data.ThemeCatalogData;
}>();

// Only overridden tokens are keys here; everything else falls back to the
// platform default shown as each input's placeholder.
const overrides = ref<Record<string, string>>({ ...props.tokens });

const form = useForm<{ tokens: Record<string, string> }>({
    tokens: { ...props.tokens },
});

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
    ...overrides.value,
}));

function updateOverrides(next: Record<string, string>): void {
    overrides.value = next;
}

function submit(): void {
    form.transform(() => ({ tokens: overrides.value })).put(
        `/admin/brands/${props.brand.id}/theme`,
        { preserveScroll: true },
    );
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
            <h1 class="mt-2 text-2xl font-semibold">{{ brand.name }} — Theme</h1>
            <p class="mt-2 max-w-2xl text-sm text-text-muted">
                Overrides apply to <code>:root</code> for every visitor on
                {{ brand.name }}'s domains (ADR-0008). Dark-mode overrides are
                a follow-up — this editor writes a single token map, so
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
                    :model-value="overrides"
                    @update:model-value="updateOverrides"
                />
                <InputError :message="form.errors.tokens" />

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
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
