<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import FooterColumnsForm from '../../../components/admin/FooterColumnsForm.vue';
import FooterLinkListForm from '../../../components/admin/FooterLinkListForm.vue';

/**
 * The footer every brand-facing page renders. The operator-wide list is
 * always here — one legal entity answers for all its brands, so these
 * entries are not per brand. A brand's own list joins it once the console
 * has a brand picked (ConsoleSelection); each list is its own form and its
 * own submit.
 *
 * Footer columns (slice 21b) follow the same operator-wide / brand-own
 * split, edited with the block composer rather than a fixed set of fields.
 */
type FooterLink = Konventix.Platform.Data.FooterLinkData;
type FooterColumn = Konventix.Platform.Data.FooterColumnData;
type TypeOption = Konventix.Platform.Data.Blocks.BlockTypeOptionData;

const props = defineProps<{
    operatorLinks: FooterLink[];
    brand: { id: string; name: string } | null;
    brandLinks: FooterLink[] | null;
    operatorColumns: FooterColumn[];
    brandColumns: FooterColumn[] | null;
    types: TypeOption[];
}>();
</script>

<template>
    <Head title="Footer" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Footer"
            description="Links shown in the footer of every brand-facing page"
        />

        <p class="text-sm text-text-muted">
            Entries appear in the order below. A <em>page link</em> navigates
            somewhere; an <em>in-page dialog</em> opens something in the page
            instead — cookie settings, once that dialog ships. A dialog entry
            whose key nothing implements yet is simply not rendered.
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
            Pick a brand in the console selector to edit that brand's own links.
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
