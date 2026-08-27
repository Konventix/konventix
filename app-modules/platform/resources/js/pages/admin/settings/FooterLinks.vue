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
import { edit as editFooter } from '@/routes/admin/settings/footer';
import FooterColumnsForm from '../../../components/admin/FooterColumnsForm.vue';
import FooterLinkListForm from '../../../components/admin/FooterLinkListForm.vue';

/**
 * The footer every brand-facing page renders. The operator-wide list is
 * always here — one legal entity answers for all its brands, so these
 * entries are not per brand and a brand cannot override them. What a brand
 * can do is add its own entries and columns after them: pick the brand in
 * the header (it defaults to the console's selection) and its two forms
 * appear beside the operator-wide ones; each list is its own form and its
 * own submit.
 *
 * Footer columns (slice 21b) follow the same operator-wide / brand-own
 * split, edited with the block composer rather than a fixed set of fields.
 */
type FooterLink = Konventix.Platform.Data.FooterLinkData;
type FooterColumn = Konventix.Platform.Data.FooterColumnData;
type TypeOption = Konventix.Platform.Data.Blocks.BlockTypeOptionData;

const props = defineProps<{
    brands: { id: string; name: string }[];
    operatorLinks: FooterLink[];
    brand: { id: string; name: string } | null;
    brandLinks: FooterLink[] | null;
    operatorColumns: FooterColumn[];
    brandColumns: FooterColumn[] | null;
    types: TypeOption[];
}>();

/**
 * The brand whose own footer is edited below the operator-wide one. A
 * default from the console selection, not a filter — there is no "all
 * brands" footer to edit, only the operator's plus one brand's at a time.
 */
function switchBrand(value: unknown): void {
    router.get(
        editFooter().url,
        { brand: String(value) },
        { preserveState: false },
    );
}
</script>

<template>
    <Head title="Footer" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Footer"
            description="Links and columns shown in the footer of every brand-facing page"
        >
            <Label for="footer-brand" class="sr-only">Brand to edit</Label>
            <Select
                :model-value="props.brand?.id ?? undefined"
                @update:model-value="switchBrand"
            >
                <SelectTrigger id="footer-brand" class="w-56">
                    <SelectValue placeholder="Add a brand's own entries…" />
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
            The operator-wide entries and columns appear on every brand and
            cannot be overridden per brand — one legal entity answers for all of
            them. A brand can add its own entries and columns, shown after the
            operator-wide ones: pick it above. A <em>page link</em> navigates
            somewhere; an <em>in-page dialog</em> opens something in the page
            instead, such as the cookie settings.
        </p>

        <FooterLinkListForm
            title="Operator-wide"
            description="Legal and policy links shown on every brand"
            :links="props.operatorLinks"
            :brand-id="null"
        />

        <FooterLinkListForm
            v-if="props.brand"
            :key="props.brand.id"
            :title="`${props.brand.name} only`"
            description="Links shown only on this brand, after the operator-wide entries"
            :links="props.brandLinks ?? []"
            :brand-id="props.brand.id"
        />

        <p v-else class="text-sm text-text-muted">
            Pick a brand above to add that brand's own links and columns.
        </p>

        <FooterColumnsForm
            title="Operator-wide columns"
            description="Footer columns shown on every brand, built from blocks"
            :columns="props.operatorColumns"
            :brand-id="null"
            :types="props.types"
        />

        <FooterColumnsForm
            v-if="props.brand"
            :key="`${props.brand.id}-columns`"
            :title="`${props.brand.name} columns`"
            description="Footer columns shown only on this brand, after the operator-wide ones"
            :columns="props.brandColumns ?? []"
            :brand-id="props.brand.id"
            :types="props.types"
        />
    </div>
</template>
