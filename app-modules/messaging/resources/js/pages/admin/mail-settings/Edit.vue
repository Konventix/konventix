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
import { update as updateMailSettings } from '@/routes/admin/mail-settings';

/**
 * One brand's sender identity, quiet-hours timezone (ADR-0041) and
 * transport (ADR-0028). The stored SMTP password never travels back here:
 * an empty password field on an already-configured transport keeps the
 * stored secret.
 */
type Props = {
    brand: { id: string; name: string };
    settings: {
        fromName: string;
        fromAddress: string;
        replyTo: string | null;
        timezone: string | null;
        useCustomTransport: boolean;
        smtpHost: string | null;
        smtpPort: number | null;
        smtpUsername: string | null;
        smtpScheme: string | null;
        hasSmtpPassword: boolean;
    };
};

const props = defineProps<Props>();

const form = useForm({
    fromName: props.settings.fromName,
    fromAddress: props.settings.fromAddress,
    replyTo: props.settings.replyTo,
    timezone: props.settings.timezone,
    useCustomTransport: props.settings.useCustomTransport,
    smtpHost: props.settings.smtpHost,
    smtpPort: props.settings.smtpPort,
    smtpUsername: props.settings.smtpUsername,
    smtpPassword: null as string | null,
    smtpScheme: props.settings.smtpScheme,
});

useUnsavedChanges(() => form.isDirty);

function submit(): void {
    form.transform((data) => ({
        ...data,
        smtpPassword: data.smtpPassword === '' ? null : data.smtpPassword,
    })).put(updateMailSettings(props.brand.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Mail settings — ${brand.name}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Mail settings — ${brand.name}`"
            description="Sender identity, transport and quiet-hours timezone for this brand"
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Sender identity</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid gap-2">
                            <Label for="from-name">From name</Label>
                            <Input id="from-name" v-model="form.fromName" />
                            <InputError :message="form.errors.fromName" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="from-address">From address</Label>
                            <Input
                                id="from-address"
                                v-model="form.fromAddress"
                                type="email"
                                placeholder="hello@brand.example"
                            />
                            <InputError :message="form.errors.fromAddress" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="reply-to">Reply-to (optional)</Label>
                            <Input
                                id="reply-to"
                                v-model="form.replyTo"
                                type="email"
                            />
                            <InputError :message="form.errors.replyTo" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="timezone">Quiet-hours timezone</Label>
                            <Input
                                id="timezone"
                                v-model="form.timezone"
                                placeholder="Europe/Berlin"
                            />
                            <p class="text-xs text-text-muted">
                                Fallback for recipients without their own
                                timezone: digests defer to 08:00 in this zone.
                            </p>
                            <InputError :message="form.errors.timezone" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Transport</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="custom-transport"
                                :model-value="form.useCustomTransport"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') => {
                                        form.useCustomTransport = value === true;
                                    }
                                "
                            />
                            <Label for="custom-transport">
                                Send through this brand's own SMTP server
                            </Label>
                        </div>
                        <p class="text-xs text-text-muted">
                            Unchecked, the brand sends through the
                            application's default mailer. Deliverability and
                            reputation are per sending domain — a brand with
                            its own domain should bring its own transport.
                        </p>

                        <template v-if="form.useCustomTransport">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="smtp-host">Host</Label>
                                    <Input id="smtp-host" v-model="form.smtpHost" />
                                    <InputError :message="form.errors.smtpHost" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="smtp-port">Port</Label>
                                    <Input
                                        id="smtp-port"
                                        v-model.number="form.smtpPort"
                                        type="number"
                                    />
                                    <InputError :message="form.errors.smtpPort" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="smtp-username">Username</Label>
                                <Input id="smtp-username" v-model="form.smtpUsername" />
                                <InputError :message="form.errors.smtpUsername" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="smtp-password">Password</Label>
                                <Input
                                    id="smtp-password"
                                    v-model="form.smtpPassword"
                                    type="password"
                                    :placeholder="
                                        settings.hasSmtpPassword
                                            ? 'Stored — leave blank to keep'
                                            : ''
                                    "
                                    autocomplete="new-password"
                                />
                                <InputError :message="form.errors.smtpPassword" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="smtp-scheme">Scheme</Label>
                                <select
                                    id="smtp-scheme"
                                    v-model="form.smtpScheme"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option :value="null">Automatic</option>
                                    <option value="smtp">smtp (STARTTLS)</option>
                                    <option value="smtps">smtps (TLS)</option>
                                </select>
                                <InputError :message="form.errors.smtpScheme" />
                            </div>
                        </template>
                    </CardContent>
                </Card>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    Save mail settings
                </Button>
                <span v-if="form.isDirty" role="status" class="text-sm text-warning">
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
