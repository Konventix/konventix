<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    preview as previewTemplate,
    reset as resetTemplate,
    update as updateTemplate,
} from '@/routes/admin/messages/templates';

type TemplateEdit = Konventix.Messaging.Data.TemplateEditData;
type LocaleText = { subject: string; body: string };

const props = defineProps<{ template: TemplateEdit }>();

// Mirrors `TemplateOverrideGuard`'s allow-list (backend): built as one
// string here rather than inline in the template, since a literal
// "{{ $name }}" inside a Vue mustache would be parsed as the start of a
// second interpolation, not as text. Looked up in `setup`, after the
// catalog is loaded (ADR-0068).
const overrideHelp = trans(
    'Simple echoes ({{ $name }}) and if/unless/foreach on payload fields only — no @php, no {!! !!}, no other directives.',
);

const locales = props.template.locales;
const activeLocale = ref<string>(
    locales.includes(props.template.defaultLocale)
        ? props.template.defaultLocale
        : locales[0],
);

function effectiveFor(locale: string): LocaleText {
    const override = props.template.overrides[locale];

    return override ? { ...override } : { ...props.template.defaults[locale] };
}

// Every locale's current draft lives here, not just the active one — so
// switching tabs never discards an edit made on another locale (mirrors
// the theme editor's two-scheme-in-one-form pattern). `originals` is the
// last-saved baseline the ADR-0038 guard compares drafts against.
const originals = reactive<Record<string, LocaleText>>(
    Object.fromEntries(locales.map((locale) => [locale, effectiveFor(locale)])),
);
const drafts = reactive<Record<string, LocaleText>>(
    Object.fromEntries(
        locales.map((locale) => [locale, { ...originals[locale] }]),
    ),
);

const isDirty = computed(() =>
    locales.some(
        (locale) =>
            drafts[locale].subject !== originals[locale].subject ||
            drafts[locale].body !== originals[locale].body,
    ),
);

useUnsavedChanges(() => isDirty.value);

const activeDraft = computed(() => drafts[activeLocale.value]);
const hasOverride = computed(
    () => props.template.overrides[activeLocale.value] !== null,
);

const form = useForm({ subject: '', body: '' });

function save(): void {
    const locale = activeLocale.value;
    form.subject = drafts[locale].subject;
    form.body = drafts[locale].body;

    form.transform((data) => ({
        locale,
        subject: data.subject,
        body: data.body,
    })).put(updateTemplate(props.template.key).url, {
        preserveScroll: true,
        onSuccess: () => {
            originals[locale] = { ...drafts[locale] };
        },
    });
}

function resetOverride(): void {
    const locale = activeLocale.value;

    if (
        !confirm(
            trans(
                'Reset this locale to the default text? Your override is deleted.',
            ),
        )
    ) {
        return;
    }

    router.delete(resetTemplate({ key: props.template.key, locale }).url, {
        preserveScroll: true,
        onSuccess: () => {
            const fallback = { ...props.template.defaults[locale] };
            drafts[locale] = fallback;
            originals[locale] = { ...fallback };
        },
    });
}

// The live preview (500ms debounced): a plain XHR, not an Inertia visit —
// this pane refreshes on every keystroke pause and must never touch
// browser history or the page's own props.
const previewHtml = ref('');
const previewError = ref<string | null>(null);
const previewLoading = ref(false);

function xsrfToken(): string | undefined {
    const raw = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return raw ? decodeURIComponent(raw) : undefined;
}

async function refreshPreview(): Promise<void> {
    const locale = activeLocale.value;
    const draft = drafts[locale];
    const token = xsrfToken();

    previewLoading.value = true;

    try {
        const response = await fetch(previewTemplate(props.template.key).url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                subject: draft.subject,
                body: draft.body,
                locale,
            }),
        });

        const data = (await response.json()) as {
            subject?: string;
            html?: string;
            message?: string;
        };

        if (!response.ok) {
            previewError.value = data.message ?? trans('Preview failed.');
            previewHtml.value = '';

            return;
        }

        previewError.value = null;
        previewHtml.value = data.html ?? '';
    } catch {
        previewError.value = trans('Preview failed.');
    } finally {
        previewLoading.value = false;
    }
}

watchDebounced(
    () => [
        activeLocale.value,
        activeDraft.value.subject,
        activeDraft.value.body,
    ],
    () => {
        void refreshPreview();
    },
    { debounce: 500, immediate: true },
);
</script>

<template>
    <Head :title="$t('Template · :key', { key: template.key })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="template.key"
            :description="
                $t(':module — overriding for :brand', {
                    module: template.module,
                    brand: template.brandName,
                })
            "
        />

        <div
            role="tablist"
            :aria-label="$t('Locale')"
            class="inline-flex rounded-lg border border-border p-1"
        >
            <button
                v-for="locale in template.locales"
                :key="locale"
                type="button"
                role="tab"
                :aria-selected="activeLocale === locale"
                :class="[
                    'rounded-md px-3 py-1 text-sm uppercase transition',
                    activeLocale === locale
                        ? 'bg-accent text-on-accent'
                        : 'text-text-muted hover:text-text',
                ]"
                @click="activeLocale = locale"
            >
                {{ locale }}
                <span
                    v-if="template.overrides[locale]"
                    aria-hidden="true"
                    class="ml-1"
                    >●</span
                >
            </button>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Default') }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label :for="`default-subject-${activeLocale}`">{{
                            $t('Subject')
                        }}</Label>
                        <Input
                            :id="`default-subject-${activeLocale}`"
                            :model-value="
                                template.defaults[activeLocale].subject
                            "
                            disabled
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`default-body-${activeLocale}`">{{
                            $t('Body')
                        }}</Label>
                        <textarea
                            :id="`default-body-${activeLocale}`"
                            :value="template.defaults[activeLocale].body"
                            disabled
                            rows="20"
                            class="w-full rounded-md border border-border bg-subtle p-3 font-mono text-xs text-text-muted"
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Your override') }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label :for="`override-subject-${activeLocale}`">{{
                            $t('Subject')
                        }}</Label>
                        <Input
                            :id="`override-subject-${activeLocale}`"
                            v-model="activeDraft.subject"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`override-body-${activeLocale}`">{{
                            $t('Body')
                        }}</Label>
                        <p class="text-xs text-text-muted">
                            {{ overrideHelp }}
                        </p>
                        <textarea
                            :id="`override-body-${activeLocale}`"
                            v-model="activeDraft.body"
                            rows="20"
                            class="w-full rounded-md border border-border bg-surface p-3 font-mono text-xs"
                        />
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="flex items-center gap-3">
                        <Button
                            type="button"
                            :disabled="form.processing"
                            @click="save"
                        >
                            {{ $t('Save override') }}
                        </Button>
                        <Button
                            v-if="hasOverride"
                            type="button"
                            variant="outline"
                            @click="resetOverride"
                        >
                            {{ $t('Reset to default') }}
                        </Button>
                        <span
                            v-if="isDirty"
                            role="status"
                            class="text-sm text-warning"
                        >
                            {{ $t('Unsaved changes') }}
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ $t('Preview') }}</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p class="text-xs text-text-muted">
                    {{
                        $t(
                            'Rendered against a sample payload — real mail carries real recipient data.',
                        )
                    }}
                </p>
                <p
                    v-if="previewError"
                    class="rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger"
                    role="status"
                >
                    {{ previewError }}
                </p>
                <!--
                    Sandboxed with no allowances at all (`sandbox=""`): this
                    is a brand-controlled template render, and it must not
                    run script, submit forms or navigate the console.
                -->
                <iframe
                    :srcdoc="previewHtml"
                    sandbox=""
                    :title="$t('Template preview')"
                    class="h-[32rem] w-full rounded-md border border-border bg-surface"
                />
            </CardContent>
        </Card>
    </div>
</template>
