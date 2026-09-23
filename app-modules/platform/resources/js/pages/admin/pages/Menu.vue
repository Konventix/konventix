<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit as editMenu } from '@/routes/admin/menu';
import BrandMenuForm from '../../../components/admin/BrandMenuForm.vue';

/**
 * A brand's topbar menu (Task 20): the entries shown after the module
 * entries, edited and saved as a whole. There is no operator-wide list —
 * the menu is a brand's own face — so the page is a brand picker (defaulting
 * to the console's selection) and that brand's form.
 */
type MenuItem = Konventix.Platform.Data.BrandMenuItemData;
type PageOption = Konventix.Platform.Data.BrandMenuPageOptionData;

const props = defineProps<{
    brands: { id: string; name: string }[];
    brand: { id: string; name: string } | null;
    items: MenuItem[] | null;
    pages: PageOption[];
}>();

function switchBrand(value: unknown): void {
    router.get(
        editMenu().url,
        { brand: String(value) },
        { preserveState: false },
    );
}
</script>

<template>
    <Head :title="$t('Menu')" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Menu')"
            :description="
                $t(
                    'The entries a brand shows in its topbar, after the module entries',
                )
            "
        >
            <Label for="menu-brand" class="sr-only">{{
                $t('Brand to edit')
            }}</Label>
            <Select
                :model-value="props.brand?.id ?? undefined"
                @update:model-value="switchBrand"
            >
                <SelectTrigger id="menu-brand" class="w-56">
                    <SelectValue :placeholder="$t('Pick a brand…')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="candidate in props.brands"
                        :key="candidate.id"
                        :value="candidate.id"
                    >
                        {{ candidate.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </AdminPageHeader>

        <p class="text-sm text-text-muted">
            {{
                $t(
                    'A “page” entry links one of the brand’s own pages and shows only to people who may read that page — its label falls back to the page’s title. A “link” entry goes anywhere. A “group” is a heading that opens the entries placed under it; one level only. Drag entries to reorder them.',
                )
            }}
        </p>

        <BrandMenuForm
            v-if="props.brand"
            :key="props.brand.id"
            :brand-id="props.brand.id"
            :brand-name="props.brand.name"
            :items="props.items ?? []"
            :pages="props.pages"
        />

        <p v-else class="text-sm text-text-muted">
            {{ $t('Pick a brand above to edit its menu.') }}
        </p>
    </div>
</template>
