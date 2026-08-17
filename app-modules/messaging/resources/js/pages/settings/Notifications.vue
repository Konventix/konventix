<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { edit, update } from '@/routes/notification-preferences';

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

const props = defineProps<{ topics: TopicRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

const channelNames: Record<string, string> = {
    mail: 'Email',
    in_app: 'In-app',
    push: 'Push',
};

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
    return form.preferences.find((p) => p.topic === topic && p.channel === channel);
}

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Choose how each kind of notification reaches you"
        />

        <p v-if="props.topics.length === 0" class="text-sm text-text-muted">
            Nothing to configure yet — notification kinds appear here as
            features start sending them.
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
                    Security-relevant — always delivered.
                </p>

                <div class="flex flex-wrap gap-6">
                    <div
                        v-for="channel in topic.channels"
                        :key="channel.channel"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`${topic.key}-${channel.channel}`"
                            :model-value="entry(topic.key, channel.channel)?.enabled"
                            :disabled="!topic.suppressible"
                            @update:model-value="
                                (value: boolean | 'indeterminate') => {
                                    const row = entry(topic.key, channel.channel);
                                    if (row) row.enabled = value === true;
                                }
                            "
                        />
                        <Label :for="`${topic.key}-${channel.channel}`">
                            {{ channelNames[channel.channel] ?? channel.channel }}
                        </Label>
                    </div>
                </div>
            </fieldset>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Save preferences</Button>
                <span v-if="form.isDirty" role="status" class="text-sm text-warning">
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
