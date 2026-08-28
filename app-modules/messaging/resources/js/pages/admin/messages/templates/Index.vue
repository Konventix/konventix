<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    edit as editTemplate,
    index as templatesIndex,
} from '@/routes/admin/messages/templates';

type TemplateRow = Konventix.Messaging.Data.TemplateSummaryData;

defineProps<{
    templates: TemplateRow[];
    brands: { id: string; name: string }[];
    filters: { brand: string | null };
}>();

/**
 * The overridden-locale badges are computed server-side for the console
 * brand — this page only lets an operator switch which brand that is,
 * mirroring the delivery log's own brand filter.
 */
function onBrandChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    router.get(
        templatesIndex().url,
        { brand: value },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Message templates" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Message templates"
            description="Rewrite a mail in your own words, preview it, then save the override — per brand"
        />

        <div class="flex items-center gap-2">
            <Label for="template-brand">Brand</Label>
            <select
                id="template-brand"
                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                :value="filters.brand ?? 'all'"
                @change="onBrandChange"
            >
                <option value="all">Select a brand…</option>
                <option
                    v-for="brand in brands"
                    :key="brand.id"
                    :value="brand.id"
                >
                    {{ brand.name }}
                </option>
            </select>
        </div>

        <div class="rounded-xl border border-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-text-muted"
                        >
                            <th scope="col" class="p-3 font-medium">Key</th>
                            <th scope="col" class="p-3 font-medium">Module</th>
                            <th scope="col" class="p-3 font-medium">Locales</th>
                            <th scope="col" class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="template in templates"
                            :key="template.key"
                            class="cursor-pointer border-b border-border last:border-0 hover:bg-surface-muted"
                            @click="router.visit(editTemplate(template.key))"
                        >
                            <td class="p-3 font-mono text-xs">
                                {{ template.key }}
                            </td>
                            <td class="p-3 text-text-muted">
                                {{ template.module }}
                            </td>
                            <td class="p-3">
                                <span class="flex flex-wrap gap-1">
                                    <Badge
                                        v-for="locale in template.locales"
                                        :key="locale"
                                        :variant="
                                            template.overriddenLocales.includes(
                                                locale,
                                            )
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{ locale
                                        }}{{
                                            template.overriddenLocales.includes(
                                                locale,
                                            )
                                                ? ' · overridden'
                                                : ''
                                        }}
                                    </Badge>
                                </span>
                            </td>
                            <td class="p-3 text-right" @click.stop>
                                <Link
                                    :href="editTemplate(template.key)"
                                    class="text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    Edit
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="templates.length === 0">
                            <td
                                colspan="4"
                                class="p-6 text-center text-text-muted"
                            >
                                No templates registered.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
