<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, Trash2 } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import BlockComposer from '@/components/admin/BlockComposer.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { edit as menuIndex } from '@/routes/admin/menu';
import {
    destroy as destroyPage,
    index as pagesIndex,
    update as updatePage,
} from '@/routes/admin/pages';
import type { BlockEditorContext } from '@/types/blocks';

type Page = Konventix.Platform.Data.StaticPageData;
type Block = Konventix.Platform.Data.Blocks.BlockData;
type TypeOption = Konventix.Platform.Data.Blocks.BlockTypeOptionData;
type Visibility = Konventix.Platform.Enums.PageVisibility;

// Computed, not a module constant: the labels are catalog lookups (ADR-0068).
const visibilityOptions = computed<
    { value: Visibility; label: string; hint: string }[]
>(() => [
    {
        value: 'public',
        label: trans('Everyone'),
        hint: trans('Anyone with the link.'),
    },
    {
        value: 'members',
        label: trans('Members only'),
        hint: trans('Signed-in accounts; everyone else gets a 404.'),
    },
    {
        value: 'registrants',
        label: trans('Registrants only'),
        hint: trans(
            "Accounts holding a confirmed registration for one of this brand's events; everyone else gets a 404.",
        ),
    },
]);

const props = defineProps<{
    page: Page;
    types: TypeOption[];
    context: BlockEditorContext;
}>();

const form = useForm<{
    title: string;
    slug: string;
    published: boolean;
    visibility: Visibility;
    /** Whether the brand's menu should carry an entry for this page (Task 20). */
    showInMenu: boolean;
    blocks: Block[];
}>({
    title: props.page.title,
    slug: props.page.slug,
    published: props.page.published,
    visibility: props.page.visibility,
    showInMenu: props.page.inMenu,
    blocks: props.page.blocks.map((block) => ({
        ...block,
        data: { ...block.data },
    })),
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.put(updatePage(props.page.id).url, { preserveScroll: true });
}

function remove(): void {
    if (
        confirm(
            trans('Delete “:name”? This cannot be undone.', {
                name: props.page.title,
            }),
        )
    ) {
        router.delete(destroyPage(props.page.id).url);
    }
}

// In script, not the template: the `<slug>` in the sentence would read as a
// tag inside the template's interpolation.
const slugHint = computed(() =>
    trans(
        "`home` is the brand's landing page; anything else answers at /pages/<slug>.",
    ),
);
</script>

<template>
    <Head :title="props.page.title" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.page.title"
            :description="$t('Page of :brand', { brand: props.page.brandName })"
        >
            <Button v-if="props.page.publicUrl" as-child variant="secondary">
                <a
                    :href="props.page.publicUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="size-4" />
                    {{ $t('View page') }}
                </a>
            </Button>
            <Button variant="ghost" @click="remove">
                <Trash2 class="size-4 text-danger" />
                {{ $t('Delete') }}
            </Button>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader
                    ><CardTitle>{{ $t('Page') }}</CardTitle></CardHeader
                >
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="page-title">{{ $t('Title') }}</Label>
                        <Input id="page-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="page-slug">{{ $t('Slug') }}</Label>
                        <Input id="page-slug" v-model="form.slug" />
                        <p class="text-xs text-text-muted">
                            {{ slugHint }}
                        </p>
                        <InputError :message="form.errors.slug" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="page-visibility">{{
                            $t('Who may read it')
                        }}</Label>
                        <Select
                            :model-value="form.visibility"
                            @update:model-value="
                                (value) =>
                                    (form.visibility = value as Visibility)
                            "
                        >
                            <SelectTrigger id="page-visibility">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in visibilityOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-text-muted">
                            {{
                                visibilityOptions.find(
                                    (option) =>
                                        option.value === form.visibility,
                                )?.hint
                            }}
                            {{
                                $t(
                                    'A menu entry for this page is shown to the same people.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.visibility" />
                    </div>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="page-published"
                                :model-value="form.published"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        (form.published = value === true)
                                "
                            />
                            <Label for="page-published" class="font-normal">{{
                                $t('Published')
                            }}</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="page-in-menu"
                                :model-value="form.showInMenu"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        (form.showInMenu = value === true)
                                "
                            />
                            <Label for="page-in-menu" class="font-normal">
                                {{ $t('Show in menu') }}
                            </Label>
                            <Link
                                :href="menuIndex().url"
                                class="text-xs text-text-muted hover:text-text"
                            >
                                {{ $t('Arrange the menu') }}
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>{{ $t('Blocks') }}</CardTitle></CardHeader
                >
                <CardContent>
                    <BlockComposer
                        v-model="form.blocks"
                        :types="props.types"
                        :errors="form.errors as Record<string, string>"
                        :context="props.context"
                    />
                    <InputError :message="form.errors.blocks" />
                </CardContent>
            </Card>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{
                    $t('Save page')
                }}</Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                    >{{ $t('Unsaved changes') }}</span
                >
                <Link
                    :href="pagesIndex().url"
                    class="text-sm text-text-muted hover:text-text"
                    >{{ $t('Back to pages') }}</Link
                >
            </div>
        </form>
    </div>
</template>
