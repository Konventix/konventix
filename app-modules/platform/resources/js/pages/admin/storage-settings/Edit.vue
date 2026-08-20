<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { update as updateStorageSettings } from '@/routes/admin/storage-settings';

/**
 * One brand's own object storage (ADR-0043 point 6). The stored secret never
 * travels back here: an empty secret field on already-configured storage
 * keeps what is stored.
 *
 * Switching storage on or off moves no file. New uploads follow this
 * setting; everything already uploaded keeps the disk recorded on its own
 * row until `storage:migrate` moves it.
 */
type Props = {
    brand: { id: string; name: string };
    itemsInOwnBucket: number;
    settings: {
        useOwnStorage: boolean;
        bucket: string | null;
        region: string | null;
        endpoint: string | null;
        prefix: string | null;
        usePathStyleEndpoint: boolean;
        publicDirect: boolean;
        url: string | null;
        accessKeyId: string | null;
        hasSecretAccessKey: boolean;
    };
};

const props = defineProps<Props>();

const form = useForm({
    useOwnStorage: props.settings.useOwnStorage,
    bucket: props.settings.bucket,
    region: props.settings.region,
    endpoint: props.settings.endpoint,
    prefix: props.settings.prefix ?? 'media',
    usePathStyleEndpoint: props.settings.usePathStyleEndpoint,
    publicDirect: props.settings.publicDirect,
    url: props.settings.url,
    accessKeyId: props.settings.accessKeyId,
    secretAccessKey: null as string | null,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.transform((data) => ({
        ...data,
        secretAccessKey:
            data.secretAccessKey === '' ? null : data.secretAccessKey,
    })).put(updateStorageSettings(props.brand.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Storage — ${brand.name}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Storage — ${brand.name}`"
            description="Keep this brand's media in a bucket the brand owns"
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Own storage</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="useOwnStorage"
                            v-model="form.useOwnStorage"
                        />
                        <div class="flex flex-col gap-1">
                            <Label for="useOwnStorage">
                                This brand brings its own bucket
                            </Label>
                            <p class="text-sm text-text-muted">
                                Off means new uploads go to the deployment's
                                media disk. Switching moves nothing — use
                                <code>storage:migrate</code> for that.
                            </p>
                        </div>
                    </div>
                    <InputError :message="form.errors.useOwnStorage" />

                    <p
                        v-if="itemsInOwnBucket > 0"
                        class="text-sm text-text-muted"
                    >
                        {{ itemsInOwnBucket }} file(s) currently live in this
                        brand's bucket. They stay readable from there; turning
                        own storage off is refused until they are migrated.
                    </p>
                </CardContent>
            </Card>

            <div v-if="form.useOwnStorage" class="grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Bucket</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="bucket">Bucket</Label>
                            <Input id="bucket" v-model="form.bucket" />
                            <InputError :message="form.errors.bucket" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="region">Region</Label>
                            <Input id="region" v-model="form.region" />
                            <InputError :message="form.errors.region" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="endpoint">Endpoint</Label>
                            <Input
                                id="endpoint"
                                v-model="form.endpoint"
                                placeholder="https://s3.example.com"
                            />
                            <InputError :message="form.errors.endpoint" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="prefix">Key prefix</Label>
                            <Input id="prefix" v-model="form.prefix" />
                            <InputError :message="form.errors.prefix" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Checkbox
                                id="usePathStyleEndpoint"
                                v-model="form.usePathStyleEndpoint"
                            />
                            <Label for="usePathStyleEndpoint">
                                Path-style endpoint
                            </Label>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Credentials and delivery</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="accessKeyId">Access key ID</Label>
                            <Input
                                id="accessKeyId"
                                v-model="form.accessKeyId"
                            />
                            <InputError :message="form.errors.accessKeyId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="secretAccessKey">
                                Secret access key
                            </Label>
                            <Input
                                id="secretAccessKey"
                                v-model="form.secretAccessKey"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    settings.hasSecretAccessKey
                                        ? 'Stored — leave blank to keep it'
                                        : ''
                                "
                            />
                            <InputError
                                :message="form.errors.secretAccessKey"
                            />
                        </div>

                        <div class="flex items-start gap-3">
                            <Checkbox
                                id="publicDirect"
                                v-model="form.publicDirect"
                            />
                            <div class="flex flex-col gap-1">
                                <Label for="publicDirect">
                                    The bucket is publicly readable
                                </Label>
                                <p class="text-sm text-text-muted">
                                    Visitors then fetch media straight from the
                                    bucket. Leave off and media is served
                                    through the application.
                                    <code>storage:doctor</code> verifies this
                                    claim.
                                </p>
                            </div>
                        </div>
                        <InputError :message="form.errors.publicDirect" />

                        <div v-if="form.publicDirect" class="grid gap-2">
                            <Label for="url">Public base URL</Label>
                            <Input
                                id="url"
                                v-model="form.url"
                                placeholder="https://cdn.example.com"
                            />
                            <InputError :message="form.errors.url" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Save
                </Button>
            </div>
        </form>
    </div>
</template>
