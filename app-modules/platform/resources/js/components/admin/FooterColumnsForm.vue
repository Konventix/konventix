<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import BlockComposer from '@/components/admin/BlockComposer.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateFooterColumns } from '@/routes/admin/settings/footer/columns';

/**
 * One footer column set — operator-wide, or one brand's own — edited and
 * saved as a whole. Same block editor a static page uses (BlockComposer),
 * restricted server-side to the block types that make sense in a footer.
 */
type FooterColumn = Konventix.Platform.Data.FooterColumnData;
type TypeOption = Konventix.Platform.Data.Blocks.BlockTypeOptionData;

const props = defineProps<{
    columns: FooterColumn[];
    brandId: string | null;
    types: TypeOption[];
    title: string;
    description: string;
}>();

const MAX_COLUMNS = 4;

const form = useForm<{ brandId: string | null; columns: FooterColumn[] }>({
    brandId: props.brandId,
    columns: props.columns.map((column) => ({
        ...column,
        blocks: column.blocks.map((block) => ({ ...block })),
    })),
});

useUnsavedChanges(() => form.isDirty);

const hintId = computed(() => `footer-columns-${props.brandId ?? 'operator'}`);

function addColumn(): void {
    form.columns.push({ heading: null, blocks: [] });
}

function removeColumn(index: number): void {
    form.columns.splice(index, 1);
}

function submit(): void {
    form.put(updateFooterColumns().url, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardContent class="pt-6">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <h2 class="text-base font-medium">{{ props.title }}</h2>
                    <p class="text-sm text-text-muted">
                        {{ props.description }}
                    </p>
                </div>

                <p
                    v-if="form.columns.length === 0"
                    class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted"
                >
                    {{ $t('No columns yet.') }}
                </p>

                <div
                    v-for="(column, index) in form.columns"
                    :key="index"
                    class="flex flex-col gap-4 rounded-lg border border-border p-4"
                >
                    <div class="flex items-center gap-2">
                        <div class="grid flex-1 gap-2">
                            <Label :for="`${hintId}-heading-${index}`">
                                {{ $t('Heading (optional)') }}
                            </Label>
                            <Input
                                :id="`${hintId}-heading-${index}`"
                                :model-value="column.heading ?? ''"
                                :placeholder="$t('Contact')"
                                @update:model-value="
                                    (value) =>
                                        (column.heading = String(value) || null)
                                "
                            />
                            <InputError
                                :message="
                                    form.errors[`columns.${index}.heading`]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="mt-6"
                            :aria-label="
                                $t('Remove column :number', {
                                    number: String(index + 1),
                                })
                            "
                            @click="removeColumn(index)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <BlockComposer
                        v-model="column.blocks"
                        :types="props.types"
                        :errors="form.errors"
                        :context="{ media: [], events: [] }"
                        :field="`columns.${index}.blocks`"
                    />
                </div>

                <Button
                    v-if="form.columns.length < MAX_COLUMNS"
                    type="button"
                    variant="secondary"
                    size="sm"
                    class="self-start"
                    @click="addColumn"
                >
                    <Plus class="size-4" />
                    {{ $t('Add column') }}
                </Button>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ $t('Save') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
