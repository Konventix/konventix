<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';
import {
    show as showMessage,
    resend as resendMessage,
} from '@/routes/admin/messages';
import { channelLabel, messageStatusLabel } from '../../../lib/labels';

type MessageDetail = Konventix.Messaging.Data.MessageLogDetailData;

const props = defineProps<{ message: MessageDetail }>();

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'sent') {
        return 'default';
    }

    return status === 'failed' ? 'destructive' : 'secondary';
}

const inFlight = ['queued', 'held'];
const isSuppressed = computed(() => props.message.status === 'suppressed');

function resend(): void {
    if (isSuppressed.value) {
        return;
    }

    if (
        !confirm(
            trans(
                'Resend this message? A fresh copy is queued and sent to the same recipient.',
            ),
        )
    ) {
        return;
    }

    router.post(resendMessage(props.message.id).url);
}
</script>

<template>
    <Head
        :title="
            $t('Message · :recipient', { recipient: message.recipientEmail })
        "
    />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="
                $t('Message · :template', { template: message.templateKey })
            "
            :description="
                $t(':brand — to :recipient', {
                    brand: message.brandName,
                    recipient: message.recipientEmail,
                })
            "
        >
            <Badge :variant="statusVariant(message.status)">{{
                messageStatusLabel(message.status)
            }}</Badge>
            <Button
                v-if="!inFlight.includes(message.status)"
                variant="outline"
                :disabled="isSuppressed"
                @click="resend"
            >
                <RotateCcw class="size-4" />
                {{ $t('Resend') }}
            </Button>
        </AdminPageHeader>

        <p v-if="isSuppressed" class="text-sm text-text-muted" role="status">
            {{ $t('Recipient is suppressed — lift the suppression first.') }}
        </p>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ $t('Delivery') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-text-muted">{{ $t('Channel') }}</dt>
                        <dd>{{ channelLabel(message.channel) }}</dd>
                        <dt class="text-text-muted">{{ $t('Template') }}</dt>
                        <dd class="font-mono text-xs">
                            {{ message.templateKey }}
                        </dd>
                        <dt class="text-text-muted">{{ $t('Topic') }}</dt>
                        <dd>{{ message.topicKey ?? '—' }}</dd>
                        <dt class="text-text-muted">{{ $t('Recipient') }}</dt>
                        <dd>
                            {{ message.recipientEmail }}
                            <span
                                v-if="message.recipientId"
                                class="block font-mono text-xs text-text-muted"
                            >
                                {{ message.recipientId }}
                            </span>
                        </dd>
                        <dt class="text-text-muted">{{ $t('Urgent') }}</dt>
                        <dd>{{ message.urgent ? $t('Yes') : $t('No') }}</dd>
                        <dt class="text-text-muted">{{ $t('Attempts') }}</dt>
                        <dd>{{ message.attempts }}</dd>
                        <dt class="text-text-muted">{{ $t('Queued') }}</dt>
                        <dd>{{ formatDateTime(message.queuedAt) || '—' }}</dd>
                        <dt class="text-text-muted">{{ $t('Sent') }}</dt>
                        <dd>{{ formatDateTime(message.sentAt) || '—' }}</dd>
                        <dt class="text-text-muted">{{ $t('Failed') }}</dt>
                        <dd>{{ formatDateTime(message.failedAt) || '—' }}</dd>
                        <dt class="text-text-muted">{{ $t('Provider id') }}</dt>
                        <dd class="font-mono text-xs">
                            {{ message.providerMessageId ?? '—' }}
                        </dd>
                        <template v-if="message.resentFromId">
                            <dt class="text-text-muted">
                                {{ $t('Resent from') }}
                            </dt>
                            <dd>
                                <a
                                    :href="
                                        showMessage(message.resentFromId).url
                                    "
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ message.resentFromId }}
                                </a>
                            </dd>
                        </template>
                    </dl>

                    <p
                        v-if="message.lastError"
                        class="mt-4 rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger"
                    >
                        {{ message.lastError }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('Attachments') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="message.attachments.length === 0"
                        class="text-sm text-text-muted"
                    >
                        {{ $t('No attachments.') }}
                    </p>
                    <ul v-else class="flex flex-col gap-1 text-sm">
                        <li v-for="name in message.attachments" :key="name">
                            {{ name }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ $t('Rendered mail') }}</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="message.bodyRedacted"
                    class="text-sm text-text-muted"
                    role="status"
                >
                    {{ $t('You may not view message contents.') }}
                </p>
                <p
                    v-else-if="message.renderedBody === null"
                    class="text-sm text-text-muted"
                    role="status"
                >
                    {{ $t('Body not logged for this template.') }}
                </p>
                <template v-else>
                    <p class="text-sm font-medium">
                        {{ message.renderedSubject }}
                    </p>
                    <!--
                        Sandboxed with no allowances at all (`sandbox=""`):
                        this is a recipient-facing HTML render from a
                        brand-controlled template, and it must not run
                        script, submit forms or navigate the console.
                    -->
                    <iframe
                        :srcdoc="message.renderedBody"
                        sandbox=""
                        :title="$t('Rendered message body')"
                        class="h-96 w-full rounded-md border border-border bg-surface"
                    />
                </template>
            </CardContent>
        </Card>

        <Card v-if="!message.bodyRedacted">
            <CardHeader>
                <CardTitle>{{ $t('Payload') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <pre class="overflow-x-auto rounded-md bg-subtle p-3 text-xs">{{
                    JSON.stringify(message.payload, null, 2)
                }}</pre>
            </CardContent>
        </Card>

        <Card v-if="message.resends.length > 0">
            <CardHeader>
                <CardTitle>{{ $t('Resends') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <ul class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="entry in message.resends"
                        :key="entry.id"
                        class="flex items-center justify-between gap-2"
                    >
                        <a
                            :href="showMessage(entry.id).url"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ formatDateTime(entry.queuedAt) || entry.id }}
                        </a>
                        <Badge :variant="statusVariant(entry.status)">{{
                            messageStatusLabel(entry.status)
                        }}</Badge>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
