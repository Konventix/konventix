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
    <Head :title="$t('Storage — :brand', { brand: brand.name })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Storage — :brand', { brand: brand.name })"
            :description="
                $t('Keep this brand’s media in a bucket the brand owns')
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Own storage') }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="useOwnStorage"
                            v-model="form.useOwnStorage"
                        />
                        <div class="flex flex-col gap-1">
                            <Label for="useOwnStorage">
                                {{ $t('This brand brings its own bucket') }}
                            </Label>
                            <p class="text-sm text-text-muted">
                                {{
                                    $t(
                                        'Off means new uploads go to the deployment’s media disk. Switching moves nothing; to move files, run',
                                    )
                                }}
                                <code>{{ 'storage:migrate' }}</code
                                >.
                            </p>
                        </div>
                    </div>
                    <InputError :message="form.errors.useOwnStorage" />

                    <p
                        v-if="itemsInOwnBucket > 0"
                        class="text-sm text-text-muted"
                    >
                        {{
                            $tChoice(
                                '{1} :count file currently lives in this brand’s bucket. It stays readable from there; turning own storage off is refused until it is migrated.|[2,*] :count files currently live in this brand’s bucket. They stay readable from there; turning own storage off is refused until they are migrated.',
                                itemsInOwnBucket,
                            )
                        }}
                    </p>
                </CardContent>
            </Card>

            <div v-if="form.useOwnStorage" class="grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Bucket') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="bucket">{{ $t('Bucket') }}</Label>
                            <Input id="bucket" v-model="form.bucket" />
                            <InputError :message="form.errors.bucket" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="region">{{ $t('Region') }}</Label>
                            <Input id="region" v-model="form.region" />
                            <InputError :message="form.errors.region" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="endpoint">{{ $t('Endpoint') }}</Label>
                            <Input
                                id="endpoint"
                                v-model="form.endpoint"
                                :placeholder="'https://s3.example.com'"
                            />
                            <InputError :message="form.errors.endpoint" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="prefix">{{ $t('Key prefix') }}</Label>
                            <Input id="prefix" v-model="form.prefix" />
                            <InputError :message="form.errors.prefix" />
                        </div>
                        <div class="flex items-center gap-3">
                            <Checkbox
                                id="usePathStyleEndpoint"
                                v-model="form.usePathStyleEndpoint"
                            />
                            <Label for="usePathStyleEndpoint">
                                {{ $t('Path-style endpoint') }}
                            </Label>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{
                            $t('Credentials and delivery')
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="accessKeyId">{{
                                $t('Access key ID')
                            }}</Label>
                            <Input
                                id="accessKeyId"
                                v-model="form.accessKeyId"
                            />
                            <InputError :message="form.errors.accessKeyId" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="secretAccessKey">
                                {{ $t('Secret access key') }}
                            </Label>
                            <Input
                                id="secretAccessKey"
                                v-model="form.secretAccessKey"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    settings.hasSecretAccessKey
                                        ? $t('Stored — leave blank to keep it')
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
                                    {{ $t('The bucket is publicly readable') }}
                                </Label>
                                <p class="text-sm text-text-muted">
                                    {{
                                        $t(
                                            'Visitors then fetch media straight from the bucket. Leave off and media is served through the application. This claim is checked by',
                                        )
                                    }}
                                    <code>{{ 'storage:doctor' }}</code
                                    >.
                                </p>
                            </div>
                        </div>
                        <InputError :message="form.errors.publicDirect" />

                        <div v-if="form.publicDirect" class="grid gap-2">
                            <Label for="url">{{ $t('Public base URL') }}</Label>
                            <Input
                                id="url"
                                v-model="form.url"
                                :placeholder="'https://cdn.example.com'"
                            />
                            <InputError :message="form.errors.url" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ $t('Save') }}
                </Button>
            </div>
        </form>
    </div>
</template>
