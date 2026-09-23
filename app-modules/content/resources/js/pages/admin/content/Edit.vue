<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime, withZoneName } from '@/lib/datetime';
import {
    archive as archiveEntry,
    index as contentIndex,
    publish as publishEntry,
    unpublish as unpublishEntry,
    update as updateEntry,
} from '@/routes/admin/content';
import { index as commentsIndex } from '@/routes/admin/content/comments';
import {
    audienceLabel,
    contentTypeLabel,
    publicationStateLabel,
    urgencyLabel,
} from '../../../lib/labels';
import { publishedAtInstant } from './publishForm';

/**
 * One entry: its content on the left, its lifecycle on the right.
 *
 * The split is the domain's, not a layout preference — saving edits content
 * and never republishes (`UpdateEntry` leaves `state` and `published_at`
 * alone), while every button in the lifecycle card is one Action with its own
 * events. `transitions` comes from the server, so the console cannot offer a
 * move the Actions would refuse.
 */
type Props = {
    // The editable half is the backend's own `ContentEntryData` (ADR-0009 —
    // no hand-written payload types), plus the read-only context the editor
    // shows around it.
    entry: Konventix.Content.Data.ContentEntryData & {
        id: string;
        state: Konventix.Content.Enums.PublicationState;
        publishedAt: string | null;
        brandName: string;
        /** Absent while the entry is not live — a draft has no reader-facing page. */
        publicUrl: string | null;
    };
    types: Konventix.Content.Enums.ContentType[];
    audiences: Konventix.Content.Enums.Audience[];
    urgencies: Konventix.Content.Enums.Urgency[];
    transitions: string[];
    comments: { pending: number; total: number };
};

const props = defineProps<Props>();

const form = useForm({
    type: props.entry.type,
    title: props.entry.title,
    slug: props.entry.slug,
    audience: props.entry.audience,
    commentsEnabled: props.entry.commentsEnabled,
    teaser: props.entry.teaser,
    body: props.entry.body,
    urgency: props.entry.urgency,
    // Empty string in the field, null on the wire — the column is nullable
    // and an empty ULID is not a value the Data object would accept.
    eventId: props.entry.eventId ?? '',
});

useUnsavedChanges(() => form.isDirty);

const isAnnouncement = computed(() => form.type === 'announcement');

const stateClasses: Record<Konventix.Content.Enums.PublicationState, string> = {
    draft: 'bg-subtle text-text-muted',
    scheduled: 'bg-accent/15 text-accent',
    published: 'bg-success/15 text-success',
    unpublished: 'bg-warning/15 text-warning',
    archived: 'bg-subtle text-text-muted',
};

/** Empty means "now"; a future moment schedules instead of publishing. */
const publishAt = ref('');

const publishLabel = computed<string>(() =>
    publishAt.value === '' ? trans('Publish') : trans('Schedule publication'),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        eventId: data.eventId === '' ? null : data.eventId,
    })).put(updateEntry(props.entry.id).url, { preserveScroll: true });
}

function publish(): void {
    router.post(
        publishEntry(props.entry.id).url,
        // An instant with an offset, never the wall-clock digits: the server
        // cannot know whose clock they were typed on (#246).
        { publishedAt: publishedAtInstant(publishAt.value) },
        { preserveScroll: true },
    );
}

function unpublish(): void {
    router.post(
        unpublishEntry(props.entry.id).url,
        {},
        { preserveScroll: true },
    );
}

function archive(): void {
    router.post(archiveEntry(props.entry.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t(':title — Content', { title: props.entry.title })" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.entry.title"
            :description="`${contentTypeLabel(props.entry.type)} · ${props.entry.brandName}`"
        >
            <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                    stateClasses[props.entry.state] ??
                    'bg-subtle text-text-muted'
                "
            >
                {{ publicationStateLabel(props.entry.state) }}
            </span>
            <Button v-if="props.entry.publicUrl" variant="outline" as-child>
                <a :href="props.entry.publicUrl" target="_blank" rel="noopener">
                    <ExternalLink class="size-4" />
                    {{ $t('View public page') }}
                </a>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="contentIndex().url">
                    <ArrowLeft class="size-4" />
                    {{ $t('All content') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>{{ $t('Content') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="entry-type">{{ $t('Type') }}</Label>
                                <select
                                    id="entry-type"
                                    v-model="form.type"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="type in props.types"
                                        :key="type"
                                        :value="type"
                                    >
                                        {{ contentTypeLabel(type) }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.type" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="entry-audience">{{
                                    $t('Visibility')
                                }}</Label>
                                <select
                                    id="entry-audience"
                                    v-model="form.audience"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="audience in props.audiences"
                                        :key="audience"
                                        :value="audience"
                                    >
                                        {{ audienceLabel(audience) }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.audience" />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="entry-title">{{ $t('Title') }}</Label>
                            <Input id="entry-title" v-model="form.title" />
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entry-slug">{{ $t('Slug') }}</Label>
                            <Input id="entry-slug" v-model="form.slug" />
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Public URL path. Changing it on a live entry breaks existing links.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entry-teaser">{{ $t('Teaser') }}</Label>
                            <textarea
                                id="entry-teaser"
                                v-model="form.teaser"
                                rows="2"
                                class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                            />
                            <InputError :message="form.errors.teaser" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entry-body">{{ $t('Body') }}</Label>
                            <textarea
                                id="entry-body"
                                v-model="form.body"
                                rows="16"
                                class="rounded-md border border-border bg-surface px-3 py-2 font-mono text-sm"
                            />
                            <InputError :message="form.errors.body" />
                        </div>

                        <div v-if="isAnnouncement" class="grid gap-2">
                            <Label for="entry-urgency">{{
                                $t('Urgency')
                            }}</Label>
                            <select
                                id="entry-urgency"
                                v-model="form.urgency"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                            >
                                <option
                                    v-for="urgency in props.urgencies"
                                    :key="urgency"
                                    :value="urgency"
                                >
                                    {{ urgencyLabel(urgency) }}
                                </option>
                            </select>
                            <p class="text-xs text-text-muted">
                                {{
                                    $t(
                                        'Intent only — Messaging decides which channels an urgent announcement reaches and when.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.urgency" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entry-event">{{
                                $t('Event ID')
                            }}</Label>
                            <Input
                                id="entry-event"
                                v-model="form.eventId"
                                :placeholder="
                                    $t(
                                        'Optional — scopes the entry to one event',
                                    )
                                "
                            />
                            <InputError :message="form.errors.eventId" />
                        </div>

                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="entry-comments"
                                :model-value="form.commentsEnabled"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') => {
                                        form.commentsEnabled = value === true;
                                    }
                                "
                            />
                            <Label for="entry-comments">{{
                                $t('Allow comments')
                            }}</Label>
                        </div>

                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="form.processing">
                                {{ $t('Save') }}
                            </Button>
                            <span
                                v-if="form.isDirty"
                                role="status"
                                class="text-sm text-warning"
                            >
                                {{ $t('Unsaved changes') }}
                            </span>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Publication') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            <template v-if="props.entry.publishedAt">
                                {{
                                    $t('Live from :time.', {
                                        time: formatDateTime(
                                            props.entry.publishedAt,
                                            withZoneName,
                                        ),
                                    })
                                }}
                            </template>
                            <template v-else>{{
                                $t('Never published.')
                            }}</template>
                        </p>

                        <div
                            v-if="props.transitions.includes('publish')"
                            class="grid gap-2"
                        >
                            <DateTimeField
                                id="entry-publish-at"
                                v-model="publishAt"
                                :label="$t('Publish at (leave empty for now)')"
                            />
                            <Button @click="publish">
                                {{ publishLabel }}
                            </Button>
                        </div>

                        <Button
                            v-if="props.transitions.includes('unpublish')"
                            variant="outline"
                            @click="unpublish"
                        >
                            {{ $t('Unpublish') }}
                        </Button>

                        <Button
                            v-if="props.transitions.includes('archive')"
                            variant="outline"
                            @click="archive"
                        >
                            {{ $t('Archive') }}
                        </Button>

                        <p
                            v-if="props.transitions.length === 0"
                            class="text-sm text-text-muted"
                        >
                            {{ $t('Archived entries stay as they are.') }}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Comments') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p class="text-sm text-text-muted">
                            {{
                                $t(
                                    ':total total, :pending awaiting moderation.',
                                    {
                                        total: String(props.comments.total),
                                        pending: String(props.comments.pending),
                                    },
                                )
                            }}
                        </p>
                        <Button variant="outline" as-child>
                            <Link :href="commentsIndex().url">
                                {{ $t('Moderation queue') }}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
