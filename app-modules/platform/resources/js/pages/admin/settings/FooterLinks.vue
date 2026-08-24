<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Trash2 } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { update as updateFooter } from '@/routes/admin/settings/footer';

/**
 * The footer every brand-facing page renders. Operator-wide: one legal
 * entity answers for all its brands, so these entries are not per brand.
 *
 * Edited and saved as one list, in the order shown — position is the array
 * index, so reordering here is the same submit as renaming, and there is no
 * separate reorder endpoint to drift out of sync.
 */
type FooterLink = Konventix.Platform.Data.FooterLinkData;

const props = defineProps<{ links: FooterLink[] }>();

const form = useForm<{ links: FooterLink[] }>({
    links: props.links.map((link) => ({ ...link })),
});

useUnsavedChanges(() => form.isDirty);

function addLink(): void {
    form.links.push({
        label: '',
        kind: 'url',
        url: '',
        dialogKey: null,
        opensInNewTab: false,
    });
}

function move(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.links.length) {
        return;
    }

    const [link] = form.links.splice(index, 1);
    form.links.splice(target, 0, link);
}

function submit(): void {
    form.put(updateFooter().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Footer" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Footer"
            description="Links shown in the footer of every brand-facing page"
        />

        <Card>
            <CardContent class="pt-6">
                <form class="flex flex-col gap-6" @submit.prevent="submit">
                    <p class="text-sm text-text-muted">
                        Entries appear in the order below. A
                        <em>page link</em> navigates somewhere; an
                        <em>in-page dialog</em> opens something in the page
                        instead — cookie settings, once that dialog ships. A
                        dialog entry whose key nothing implements yet is simply
                        not rendered.
                    </p>

                    <p
                        v-if="form.links.length === 0"
                        class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
                    >
                        No entries yet. The footer still shows the version and
                        the Konventix credit.
                    </p>

                    <fieldset
                        v-for="(link, index) in form.links"
                        :key="index"
                        class="flex flex-col gap-4 rounded-lg border border-border p-4"
                    >
                        <legend class="px-1 text-sm font-medium">
                            Entry {{ index + 1 }}
                        </legend>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label :for="`label-${index}`">Label</Label>
                                <Input
                                    :id="`label-${index}`"
                                    v-model="link.label"
                                    placeholder="Imprint"
                                />
                                <InputError
                                    :message="
                                        form.errors[`links.${index}.label`]
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`kind-${index}`">Type</Label>
                                <Select v-model="link.kind">
                                    <SelectTrigger :id="`kind-${index}`">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="url">
                                            Page link
                                        </SelectItem>
                                        <SelectItem value="dialog">
                                            In-page dialog
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div v-if="link.kind === 'url'" class="grid gap-2">
                            <Label :for="`url-${index}`">Destination</Label>
                            <Input
                                :id="`url-${index}`"
                                v-model="link.url"
                                placeholder="/pages/imprint"
                            />
                            <p class="text-xs text-text-muted">
                                A path on this site, an https:// address, or a
                                mailto: address.
                            </p>
                            <InputError
                                :message="form.errors[`links.${index}.url`]"
                            />
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    :id="`newtab-${index}`"
                                    :model-value="link.opensInNewTab"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            (link.opensInNewTab =
                                                value === true)
                                    "
                                />
                                <Label
                                    :for="`newtab-${index}`"
                                    class="font-normal"
                                >
                                    Open in a new tab
                                </Label>
                            </div>
                        </div>

                        <div v-else class="grid gap-2">
                            <Label :for="`dialog-${index}`">Dialog key</Label>
                            <Input
                                :id="`dialog-${index}`"
                                :model-value="link.dialogKey ?? ''"
                                placeholder="cookie-settings"
                                @update:model-value="
                                    (value: string | number | null) =>
                                        (link.dialogKey =
                                            value === null
                                                ? null
                                                : String(value))
                                "
                            />
                            <InputError
                                :message="
                                    form.errors[`links.${index}.dialogKey`]
                                "
                            />
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="index === 0"
                                :aria-label="`Move ${link.label || `entry ${index + 1}`} up`"
                                @click="move(index, -1)"
                            >
                                <ArrowUp class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="index === form.links.length - 1"
                                :aria-label="`Move ${link.label || `entry ${index + 1}`} down`"
                                @click="move(index, 1)"
                            >
                                <ArrowDown class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove ${link.label || `entry ${index + 1}`}`"
                                @click="form.links.splice(index, 1)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </fieldset>

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="self-start"
                        @click="addLink"
                    >
                        Add entry
                    </Button>

                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="form.processing">
                            Save footer
                        </Button>
                        <span
                            v-if="form.isDirty"
                            role="status"
                            class="text-sm text-warning"
                        >
                            Unsaved changes
                        </span>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
