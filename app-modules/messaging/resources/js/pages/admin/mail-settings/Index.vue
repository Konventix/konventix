<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit as editMailSettings } from '@/routes/admin/mail-settings';

/**
 * Per-brand sender identity and transport (ADR-0028). A brand without a
 * row sends through the application's default mailer — a legitimate state,
 * shown as such.
 */
type BrandRow = {
    id: string;
    name: string;
    fromName: string | null;
    fromAddress: string | null;
    timezone: string | null;
    customTransport: boolean;
};

defineProps<{ brands: BrandRow[] }>();
</script>

<template>
    <Head title="Mail settings" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Mail settings"
            description="Sender identity, transport and quiet-hours timezone per brand"
        />

        <div class="rounded-xl border border-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-text-muted"
                        >
                            <th scope="col" class="p-3 font-medium">Brand</th>
                            <th scope="col" class="p-3 font-medium">Sender</th>
                            <th scope="col" class="p-3 font-medium">
                                Transport
                            </th>
                            <th scope="col" class="p-3 font-medium">
                                Timezone
                            </th>
                            <th scope="col" class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="brand in brands"
                            :key="brand.id"
                            class="cursor-pointer border-b border-border last:border-0 hover:bg-surface-muted"
                            @click="router.visit(editMailSettings(brand.id))"
                        >
                            <td class="p-3 font-medium">{{ brand.name }}</td>
                            <td class="p-3">
                                <template v-if="brand.fromAddress">
                                    {{ brand.fromName }}
                                    <span class="text-text-muted">
                                        &lt;{{ brand.fromAddress }}&gt;
                                    </span>
                                </template>
                                <span v-else class="text-text-muted">
                                    Application default
                                </span>
                            </td>
                            <td class="p-3">
                                <Badge
                                    :variant="
                                        brand.customTransport
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        brand.customTransport
                                            ? 'Own SMTP'
                                            : 'Default mailer'
                                    }}
                                </Badge>
                            </td>
                            <td class="p-3 text-text-muted">
                                {{ brand.timezone ?? '—' }}
                            </td>
                            <td class="p-3 text-right" @click.stop>
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="editMailSettings(brand.id)"
                                        >Edit</Link
                                    >
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="brands.length === 0">
                            <td
                                colspan="5"
                                class="p-6 text-center text-text-muted"
                            >
                                No brands yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
