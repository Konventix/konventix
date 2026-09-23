<script setup lang="ts">
import { Head, setLayoutProps, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { edit, update } from '@/routes/notification-preferences';
import {
    destroy as pushDestroy,
    store as pushStore,
} from '@/routes/push-subscriptions';
import { channelLabel } from '../../lib/labels';

/**
 * Rendered from the topic registry (ADR-0041): a new notification kind
 * appears here by declaration alone. Non-suppressible topics render as
 * always-on — security notices deliberately offer no choice.
 */
type TopicChannel = { channel: string; enabled: boolean };
type TopicRow = {
    key: string;
    label: string;
    suppressible: boolean;
    channels: TopicChannel[];
};

const props = defineProps<{
    topics: TopicRow[];
    vapidPublicKey: string | null;
}>();

// Set from `setup` rather than `defineOptions`: `defineOptions` is hoisted
// to module scope, and the breadcrumb is a catalog lookup — the catalog may
// still be loading when the page chunk is evaluated (ADR-0068).
setLayoutProps({
    breadcrumbs: [
        {
            title: trans('Notification settings'),
            href: edit(),
        },
    ],
});

const form = useForm({
    preferences: props.topics.flatMap((topic) =>
        topic.channels.map((channel) => ({
            topic: topic.key,
            channel: channel.channel,
            enabled: channel.enabled,
        })),
    ),
});

useUnsavedChanges(() => form.isDirty);

function entry(topic: string, channel: string) {
    return form.preferences.find(
        (p) => p.topic === topic && p.channel === channel,
    );
}

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}

/**
 * Push on this device (ADR-0041 point 7): the subscription lives in the
 * browser's push manager; the backend only mirrors it. `push-sw.js` is a
 * static worker that renders the typed payload — nothing else.
 *
 * The permission is the browser's, and it is asked for exactly once (#294):
 * after `denied` no call to `requestPermission()` ever prompts again — the
 * person has to allow the site in the browser's own settings. So the page
 * says how, follows that change live through the Permissions API instead
 * of demanding a reload, and tells the difference between "declined" and
 * "closed the prompt without answering" (`default` stays `default`).
 */
const browserSupportsPush =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

const pushSupported = browserSupportsPush && props.vapidPublicKey !== null;

const permission = ref<NotificationPermission>('default');
const pushEnabled = ref(false);
const pushBusy = ref(false);
const promptDismissed = ref(false);

/**
 * Returns a `Uint8Array<ArrayBuffer>` rather than the `ArrayBufferLike` that
 * `Uint8Array.from` infers: `applicationServerKey` takes a `BufferSource`, and
 * a shared-memory-backed view is not one.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = window.atob(
        (base64 + padding).replace(/-/g, '+').replace(/_/g, '/'),
    );
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let index = 0; index < raw.length; index += 1) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
}

async function pushRequest(
    url: string,
    method: 'POST' | 'DELETE',
    body: Record<string, unknown>,
): Promise<void> {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Push subscription request failed: ${response.status}`);
    }
}

async function refreshPushState(): Promise<void> {
    permission.value = Notification.permission;

    const registration =
        await navigator.serviceWorker.getRegistration('/push-sw.js');
    pushEnabled.value = !!(await registration?.pushManager.getSubscription());
}

onMounted(async () => {
    if (!pushSupported) {
        return;
    }

    await refreshPushState();

    // Follow the browser's site settings live: someone who unblocks the
    // site in the address bar's permission panel comes back to a page
    // that already offers the button. Safari does not know the
    // `notifications` permission name and throws — then "Check again"
    // is the way back.
    try {
        const status = await navigator.permissions.query({
            name: 'notifications',
        });
        status.addEventListener('change', () => {
            void refreshPushState();
        });
    } catch {
        // No live updates on this browser; the manual check remains.
    }
});

/**
 * Asks for the permission only when it is still undecided; `granted` skips
 * the prompt, `denied` never reaches here (the page shows the way to the
 * browser settings instead). Runs from the click itself — browsers refuse
 * a prompt that is not the direct result of a user gesture.
 */
async function enablePush(): Promise<void> {
    if (!props.vapidPublicKey) {
        return;
    }

    pushBusy.value = true;
    promptDismissed.value = false;

    try {
        if (Notification.permission !== 'granted') {
            const answer = await Notification.requestPermission();
            permission.value = answer;

            if (answer !== 'granted') {
                promptDismissed.value = answer === 'default';

                return;
            }
        }

        const registration =
            await navigator.serviceWorker.register('/push-sw.js');
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(props.vapidPublicKey),
        });

        const json = subscription.toJSON();
        await pushRequest(pushStore().url, 'POST', {
            endpoint: json.endpoint,
            p256dh: json.keys?.p256dh,
            auth: json.keys?.auth,
        });

        pushEnabled.value = true;
    } finally {
        pushBusy.value = false;
    }
}

async function disablePush(): Promise<void> {
    pushBusy.value = true;

    try {
        const registration =
            await navigator.serviceWorker.getRegistration('/push-sw.js');
        const subscription = await registration?.pushManager.getSubscription();

        if (subscription) {
            await pushRequest(pushDestroy().url, 'DELETE', {
                endpoint: subscription.endpoint,
            });
            await subscription.unsubscribe();
        }

        pushEnabled.value = false;
    } finally {
        pushBusy.value = false;
    }
}
</script>

<template>
    <Head :title="$t('Notification settings')" />

    <h1 class="sr-only">{{ $t('Notification settings') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Notifications')"
            :description="
                $t('Choose how each kind of notification reaches you')
            "
        />

        <p v-if="props.topics.length === 0" class="text-sm text-text-muted">
            {{
                $t(
                    'Nothing to configure yet — notification kinds appear here as features start sending them.',
                )
            }}
        </p>

        <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
            <fieldset
                v-for="topic in props.topics"
                :key="topic.key"
                class="flex flex-col gap-2 rounded-md border border-border p-4"
            >
                <legend class="px-1 text-sm font-medium text-text">
                    {{ topic.label }}
                </legend>

                <p v-if="!topic.suppressible" class="text-xs text-text-muted">
                    {{ $t('Security-relevant — always delivered.') }}
                </p>

                <div class="flex flex-wrap gap-6">
                    <div
                        v-for="channel in topic.channels"
                        :key="channel.channel"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`${topic.key}-${channel.channel}`"
                            :model-value="
                                entry(topic.key, channel.channel)?.enabled
                            "
                            :disabled="!topic.suppressible"
                            @update:model-value="
                                (value: boolean | 'indeterminate') => {
                                    const row = entry(
                                        topic.key,
                                        channel.channel,
                                    );
                                    if (row) row.enabled = value === true;
                                }
                            "
                        />
                        <Label :for="`${topic.key}-${channel.channel}`">
                            {{ channelLabel(channel.channel) }}
                        </Label>
                    </div>
                </div>
            </fieldset>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{
                    $t('Save preferences')
                }}</Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-warning"
                >
                    {{ $t('Unsaved changes') }}
                </span>
            </div>
        </form>

        <div
            v-if="pushSupported"
            class="flex flex-col gap-2 rounded-md border border-border p-4"
        >
            <h2 class="text-sm font-medium text-text">
                {{ $t('Push on this device') }}
            </h2>
            <p class="text-xs text-text-muted">
                {{
                    $t(
                        'Browser notifications reach this device even while the site is closed. Each device is enabled separately.',
                    )
                }}
            </p>
            <template v-if="permission === 'denied'">
                <p class="text-sm text-text" role="status">
                    {{
                        $t(
                            'Notifications are blocked for this site. The browser will not ask again by itself — allow them in the site settings (the lock or tune icon next to the address bar, then “Notifications”), and check again here.',
                        )
                    }}
                </p>
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="pushBusy"
                        @click="refreshPushState"
                    >
                        {{ $t('Check again') }}
                    </Button>
                </div>
            </template>
            <template v-else>
                <p
                    v-if="promptDismissed"
                    class="text-sm text-text-muted"
                    role="status"
                >
                    {{
                        $t(
                            'The browser closed the permission prompt without an answer. Try again and choose “Allow”.',
                        )
                    }}
                </p>
                <div>
                    <Button
                        v-if="!pushEnabled"
                        type="button"
                        variant="outline"
                        :disabled="pushBusy"
                        @click="enablePush"
                    >
                        {{
                            permission === 'granted'
                                ? $t('Enable on this device')
                                : $t('Allow notifications on this device')
                        }}
                    </Button>
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        :disabled="pushBusy"
                        @click="disablePush"
                    >
                        {{ $t('Disable on this device') }}
                    </Button>
                </div>
            </template>
        </div>
        <!--
            Two reasons for no push, told apart, so the person testing the
            cutover does not read "no VAPID keys on this deployment" as "my
            browser cannot do this" — or the other way round.
        -->
        <p v-else-if="!browserSupportsPush" class="text-xs text-text-muted">
            {{ $t('This browser does not support push notifications.') }}
        </p>
        <p v-else class="text-xs text-text-muted">
            {{ $t('Push notifications are not set up on this installation.') }}
        </p>
    </div>
</template>
