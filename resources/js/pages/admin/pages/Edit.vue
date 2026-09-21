<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, Trash2 } from '@lucide/vue';
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

const visibilityOptions: { value: Visibility; label: string; hint: string }[] =
    [
        { value: 'public', label: 'Everyone', hint: 'Anyone with the link.' },
        {
            value: 'members',
            label: 'Members only',
            hint: 'Signed-in accounts; everyone else gets a 404.',
        },
        {
            value: 'registrants',
            label: 'Registrants only',
            hint: "Accounts holding a confirmed registration for one of this brand's events; everyone else gets a 404.",
        },
    ];

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
    if (confirm(`Delete "${props.page.title}"? This cannot be undone.`)) {
        router.delete(destroyPage(props.page.id).url);
    }
}
</script>

<template>
    <Head :title="props.page.title" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.page.title"
            :description="`Page of ${props.page.brandName}`"
        >
            <Button v-if="props.page.publicUrl" as-child variant="secondary">
                <a
                    :href="props.page.publicUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="size-4" />
                    View page
                </a>
            </Button>
            <Button variant="ghost" @click="remove">
                <Trash2 class="size-4 text-danger" />
                Delete
            </Button>
        </AdminPageHeader>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader><CardTitle>Page</CardTitle></CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="page-title">Title</Label>
                        <Input id="page-title" v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="page-slug">Slug</Label>
                        <Input id="page-slug" v-model="form.slug" />
                        <p class="text-xs text-text-muted">
                            `home` is the brand's landing page; anything else
                            answers at /pages/&lt;slug&gt;.
                        </p>
                        <InputError :message="form.errors.slug" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="page-visibility">Who may read it</Label>
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
                            A menu entry for this page is shown to the same
                            people.
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
                            <Label for="page-published" class="font-normal"
                                >Published</Label
                            >
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
                                Show in menu
                            </Label>
                            <Link
                                :href="menuIndex().url"
                                class="text-xs text-text-muted hover:text-text"
                            >
                                Arrange the menu
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Blocks</CardTitle></CardHeader>
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
                <Button type="submit" :disabled="form.processing"
                    >Save page</Button
                >
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                    >Unsaved changes</span
                >
                <Link
                    :href="pagesIndex().url"
                    class="text-sm text-text-muted hover:text-text"
                    >Back to pages</Link
                >
            </div>
        </form>
    </div>
</template>
