<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    show as showMessage,
    resend as resendMessage,
} from '@/routes/admin/messages';

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

function resend(): void {
    if (
        !confirm(
            'Resend this message? A fresh copy is queued and sent to the same recipient.',
        )
    ) {
        return;
    }

    router.post(resendMessage(props.message.id).url);
}
</script>

<template>
    <Head :title="`Message · ${message.recipientEmail}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`Message · ${message.templateKey}`"
            :description="`${message.brandName} — to ${message.recipientEmail}`"
        >
            <Badge :variant="statusVariant(message.status)">{{
                message.status
            }}</Badge>
            <Button
                v-if="!inFlight.includes(message.status)"
                variant="outline"
                @click="resend"
            >
                <RotateCcw class="size-4" />
                Resend
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="xl:col-span-2">
                <CardHeader>
                    <CardTitle>Delivery</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-text-muted">Channel</dt>
                        <dd>{{ message.channel }}</dd>
                        <dt class="text-text-muted">Template</dt>
                        <dd class="font-mono text-xs">
                            {{ message.templateKey }}
                        </dd>
                        <dt class="text-text-muted">Topic</dt>
                        <dd>{{ message.topicKey ?? '—' }}</dd>
                        <dt class="text-text-muted">Recipient</dt>
                        <dd>
                            {{ message.recipientEmail }}
                            <span
                                v-if="message.recipientId"
                                class="block font-mono text-xs text-text-muted"
                            >
                                {{ message.recipientId }}
                            </span>
                        </dd>
                        <dt class="text-text-muted">Urgent</dt>
                        <dd>{{ message.urgent ? 'Yes' : 'No' }}</dd>
                        <dt class="text-text-muted">Attempts</dt>
                        <dd>{{ message.attempts }}</dd>
                        <dt class="text-text-muted">Queued</dt>
                        <dd>{{ message.queuedAt ?? '—' }}</dd>
                        <dt class="text-text-muted">Sent</dt>
                        <dd>{{ message.sentAt ?? '—' }}</dd>
                        <dt class="text-text-muted">Failed</dt>
                        <dd>{{ message.failedAt ?? '—' }}</dd>
                        <dt class="text-text-muted">Provider id</dt>
                        <dd class="font-mono text-xs">
                            {{ message.providerMessageId ?? '—' }}
                        </dd>
                        <template v-if="message.resentFromId">
                            <dt class="text-text-muted">Resent from</dt>
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
                    <CardTitle>Attachments</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="message.attachments.length === 0"
                        class="text-sm text-text-muted"
                    >
                        No attachments.
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
                <CardTitle>Rendered mail</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="message.bodyRedacted"
                    class="text-sm text-text-muted"
                    role="status"
                >
                    You may not view message contents.
                </p>
                <p
                    v-else-if="message.renderedBody === null"
                    class="text-sm text-text-muted"
                    role="status"
                >
                    Body not logged for this template.
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
                        title="Rendered message body"
                        class="h-96 w-full rounded-md border border-border bg-surface"
                    />
                </template>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Payload</CardTitle>
            </CardHeader>
            <CardContent>
                <pre class="overflow-x-auto rounded-md bg-subtle p-3 text-xs">{{
                    JSON.stringify(message.payload, null, 2)
                }}</pre>
            </CardContent>
        </Card>

        <Card v-if="message.resends.length > 0">
            <CardHeader>
                <CardTitle>Resends</CardTitle>
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
                            {{ entry.queuedAt ?? entry.id }}
                        </a>
                        <Badge :variant="statusVariant(entry.status)">{{
                            entry.status
                        }}</Badge>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
