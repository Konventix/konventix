<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit as editStorageSettings } from '@/routes/admin/storage-settings';

/**
 * Which brands keep their media in their own bucket (ADR-0043 point 6). A
 * brand without settings uses the deployment's media disk — the normal case,
 * shown as such rather than as something missing.
 */
type BrandRow = {
    id: string;
    name: string;
    bucket: string | null;
    publicDirect: boolean;
    diskForNewUploads: string;
    itemsInOwnBucket: number;
};

defineProps<{ brands: BrandRow[]; deploymentDisk: string }>();
</script>

<template>
    <Head title="Storage" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            title="Storage"
            :description="`Where each brand's media is kept — the deployment uses '${deploymentDisk}' unless a brand brings its own bucket`"
        />

        <div class="rounded-xl border border-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-text-muted">
                            <th class="p-3 font-medium">Brand</th>
                            <th class="p-3 font-medium">New uploads go to</th>
                            <th class="p-3 font-medium">Delivery</th>
                            <th class="p-3 font-medium">Files in own bucket</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="brand in brands"
                            :key="brand.id"
                            class="cursor-pointer border-b border-border last:border-0 hover:bg-surface-muted"
                            @click="router.visit(editStorageSettings(brand.id))"
                        >
                            <td class="p-3 font-medium">{{ brand.name }}</td>
                            <td class="p-3">
                                <template v-if="brand.bucket">
                                    {{ brand.bucket }}
                                    <span class="text-text-muted">
                                        (own bucket)
                                    </span>
                                </template>
                                <span v-else class="text-text-muted">
                                    {{ deploymentDisk }} (deployment)
                                </span>
                            </td>
                            <td class="p-3">
                                <Badge
                                    v-if="brand.bucket"
                                    :variant="brand.publicDirect ? 'default' : 'secondary'"
                                >
                                    {{
                                        brand.publicDirect
                                            ? 'Public bucket'
                                            : 'Through the app'
                                    }}
                                </Badge>
                                <span v-else class="text-text-muted">—</span>
                            </td>
                            <!-- Existing files keep the disk recorded on their
                                 own row, so this can be non-zero even after
                                 storage is switched back off. -->
                            <td class="p-3 tabular-nums text-text-muted">
                                {{ brand.itemsInOwnBucket }}
                            </td>
                            <td class="p-3 text-right" @click.stop>
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="editStorageSettings(brand.id)">
                                        Edit
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="brands.length === 0">
                            <td colspan="5" class="p-6 text-center text-text-muted">
                                No brands yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
