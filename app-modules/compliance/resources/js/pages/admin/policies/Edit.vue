<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    edit as editPolicy,
    index as policiesIndex,
    update as updatePolicy,
} from '@/routes/admin/policies';
import {
    publish as publishVersion,
    store as storeVersion,
    update as updateVersion,
} from '@/routes/admin/policy-versions';

/**
 * One policy: its identity on the left, its versions on the right.
 *
 * The split is the domain's. A published version is immutable — consent rows
 * point at it and mean "agreed to this text" — so the editor turns read-only
 * the moment a version leaves draft, and the way forward is a new version,
 * not an edit. The console refuses exactly what `UpdatePolicyVersion` would.
 */
type VersionRow = {
    id: string;
    version: string;
    state: Konventix.Compliance.Enums.PolicyVersionState;
    requiresConsent: boolean;
    effectiveAt: string | null;
    publishedAt: string | null;
    consents: number;
    editable: boolean;
};

type Props = {
    policy: Konventix.Compliance.Data.PolicyData & {
        id: string;
        brandName: string;
        slugFrozen: boolean;
    };
    versions: VersionRow[];
    selected:
        | (Konventix.Compliance.Data.PolicyVersionData & {
              id: string;
              state: Konventix.Compliance.Enums.PolicyVersionState;
              editable: boolean;
              preview: string;
          })
        | null;
};

const props = defineProps<Props>();

const policyForm = useForm({
    name: props.policy.name,
    slug: props.policy.slug,
});

/**
 * `datetime-local` wants `YYYY-MM-DDTHH:MM`; the backend sends and accepts a
 * plain datetime string. Converting in both directions here keeps the Data
 * object free of a browser input's formatting quirk.
 */
function toLocalInput(value: string | null): string {
    return value === null ? '' : value.replace(' ', 'T').slice(0, 16);
}

const versionForm = useForm({
    version: props.selected?.version ?? '',
    content: props.selected?.content ?? '',
    requiresConsent: props.selected?.requiresConsent ?? true,
    lawfulBasis: props.selected?.lawfulBasis ?? 'consent',
    effectiveAt: toLocalInput(props.selected?.effectiveAt ?? null),
});

useUnsavedChanges(() => policyForm.isDirty || versionForm.isDirty);

const stateClasses: Record<
    Konventix.Compliance.Enums.PolicyVersionState,
    string
> = {
    draft: 'bg-subtle text-text-muted',
    published: 'bg-success/15 text-success',
    superseded: 'bg-warning/15 text-warning',
};

const showPreview = ref(false);

const canPublish = computed(
    () => props.selected !== null && props.selected.state === 'draft',
);

const draftOpen = ref(false);

const draftForm = useForm({
    version: '',
    content: '',
    requiresConsent: true,
    lawfulBasis: 'consent',
    effectiveAt: '',
});

function savePolicy(): void {
    policyForm.put(updatePolicy(props.policy.id).url, { preserveScroll: true });
}

function saveVersion(): void {
    if (props.selected === null) {
        return;
    }

    versionForm
        .transform((data) => ({
            ...data,
            effectiveAt: data.effectiveAt === '' ? null : data.effectiveAt,
        }))
        .put(updateVersion(props.selected.id).url, { preserveScroll: true });
}

function createDraft(): void {
    draftForm
        .transform((data) => ({
            ...data,
            effectiveAt: data.effectiveAt === '' ? null : data.effectiveAt,
        }))
        .post(storeVersion(props.policy.id).url, {
            onSuccess: () => {
                draftForm.reset();
                draftOpen.value = false;
            },
        });
}

function publish(): void {
    if (props.selected === null) {
        return;
    }

    router.post(
        publishVersion(props.selected.id).url,
        {
            effectiveAt:
                versionForm.effectiveAt === '' ? null : versionForm.effectiveAt,
        },
        { preserveScroll: true },
    );
}

function openVersion(id: string): void {
    router.get(
        editPolicy(props.policy.id).url,
        { version: id },
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <Head :title="`${props.policy.name} — Policies`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.policy.name"
            :description="`${props.policy.slug} · ${props.policy.brandName}`"
        >
            <Button variant="outline" as-child>
                <Link :href="policiesIndex().url">
                    <ArrowLeft class="size-4" />
                    All policies
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {{
                                props.selected
                                    ? `Version ${props.selected.version}`
                                    : 'No versions yet'
                            }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="props.selected === null"
                            class="text-sm text-text-muted"
                        >
                            Draft the first version to give this policy a text.
                        </p>

                        <form
                            v-else
                            class="flex flex-col gap-4"
                            @submit.prevent="saveVersion"
                        >
                            <p
                                v-if="!props.selected.editable"
                                class="rounded-md bg-warning/10 p-3 text-sm text-warning"
                                role="status"
                            >
                                This version is
                                {{ props.selected.state }} and can no longer be
                                edited — people agreed to this exact text. Draft
                                a new version instead.
                            </p>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="version-label">Version</Label>
                                    <Input
                                        id="version-label"
                                        v-model="versionForm.version"
                                        :disabled="!props.selected.editable"
                                    />
                                    <InputError
                                        :message="versionForm.errors.version"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="version-effective">
                                        Effective from
                                    </Label>
                                    <Input
                                        id="version-effective"
                                        v-model="versionForm.effectiveAt"
                                        type="datetime-local"
                                        :disabled="!props.selected.editable"
                                    />
                                    <InputError
                                        :message="
                                            versionForm.errors.effectiveAt
                                        "
                                    />
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="version-consent"
                                    :model-value="versionForm.requiresConsent"
                                    :disabled="!props.selected.editable"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') => {
                                            versionForm.requiresConsent =
                                                value === true;
                                        }
                                    "
                                />
                                <Label for="version-consent">
                                    Requires explicit agreement
                                </Label>
                            </div>
                            <p class="text-xs text-text-muted">
                                A required version puts the consent screen in
                                front of everyone who has not agreed to it, on
                                every route that carries the gate.
                            </p>

                            <div class="grid gap-2">
                                <Label for="version-lawful-basis">
                                    Lawful basis
                                </Label>
                                <Select
                                    v-model="versionForm.lawfulBasis"
                                    :disabled="!props.selected.editable"
                                >
                                    <SelectTrigger
                                        id="version-lawful-basis"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="consent">
                                            Consent — the person may withdraw at
                                            any time
                                        </SelectItem>
                                        <SelectItem value="contract">
                                            Contract — withdrawal is a request
                                        </SelectItem>
                                        <SelectItem value="legitimate_interest">
                                            Legitimate interest — withdrawal is
                                            a request
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="versionForm.errors.lawfulBasis"
                                />
                            </div>

                            <div class="grid gap-2">
                                <div class="flex items-center justify-between">
                                    <Label for="version-content">
                                        Text (Markdown)
                                    </Label>
                                    <button
                                        type="button"
                                        class="text-xs text-text-muted underline hover:text-text"
                                        @click="showPreview = !showPreview"
                                    >
                                        {{
                                            showPreview
                                                ? 'Hide preview'
                                                : 'Show preview'
                                        }}
                                    </button>
                                </div>
                                <textarea
                                    id="version-content"
                                    v-model="versionForm.content"
                                    rows="20"
                                    :disabled="!props.selected.editable"
                                    class="rounded-md border border-border bg-surface px-3 py-2 font-mono text-sm"
                                />
                                <InputError
                                    :message="versionForm.errors.content"
                                />
                                <!-- Rendered server-side by PolicyMarkdown, which strips raw HTML. -->
                                <!-- eslint-disable-next-line vue/no-v-html -->
                                <article
                                    v-if="showPreview"
                                    class="rounded-md border border-border p-3 text-sm"
                                    v-html="props.selected.preview"
                                />
                            </div>

                            <div
                                v-if="props.selected.editable"
                                class="flex items-center gap-3"
                            >
                                <Button
                                    type="submit"
                                    :disabled="versionForm.processing"
                                >
                                    Save draft
                                </Button>
                                <span
                                    v-if="versionForm.isDirty"
                                    role="status"
                                    class="text-sm text-warning"
                                >
                                    Unsaved changes
                                </span>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Policy</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="savePolicy"
                        >
                            <div class="grid gap-2">
                                <Label for="policy-name">Name</Label>
                                <Input
                                    id="policy-name"
                                    v-model="policyForm.name"
                                />
                                <InputError :message="policyForm.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="policy-slug">Slug</Label>
                                <Input
                                    id="policy-slug"
                                    v-model="policyForm.slug"
                                    :disabled="props.policy.slugFrozen"
                                />
                                <p
                                    v-if="props.policy.slugFrozen"
                                    class="text-xs text-text-muted"
                                >
                                    Frozen: a version has been published, and
                                    consent records resolve through this URL.
                                </p>
                                <InputError :message="policyForm.errors.slug" />
                            </div>
                            <div class="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    :disabled="policyForm.processing"
                                >
                                    Save
                                </Button>
                                <span
                                    v-if="policyForm.isDirty"
                                    role="status"
                                    class="text-sm text-warning"
                                >
                                    Unsaved changes
                                </span>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Versions</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <button
                            v-for="version in props.versions"
                            :key="version.id"
                            type="button"
                            class="flex flex-col gap-1 rounded-md border p-3 text-left"
                            :class="
                                version.id === props.selected?.id
                                    ? 'border-accent'
                                    : 'border-border hover:bg-surface-muted'
                            "
                            @click="openVersion(version.id)"
                        >
                            <span class="flex items-center justify-between">
                                <span class="font-medium">
                                    {{ version.version }}
                                </span>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="stateClasses[version.state]"
                                >
                                    {{ version.state }}
                                </span>
                            </span>
                            <span class="text-xs text-text-muted">
                                {{ version.effectiveAt ?? 'no date' }} ·
                                {{ version.consents }} agreement{{
                                    version.consents === 1 ? '' : 's'
                                }}
                            </span>
                        </button>

                        <p
                            v-if="props.versions.length === 0"
                            class="text-sm text-text-muted"
                        >
                            No versions yet.
                        </p>

                        <Dialog v-model:open="draftOpen">
                            <DialogTrigger as-child>
                                <Button variant="outline">
                                    <Plus class="size-4" />
                                    New draft version
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>New version</DialogTitle>
                                </DialogHeader>
                                <form
                                    class="flex flex-col gap-4"
                                    @submit.prevent="createDraft"
                                >
                                    <div class="grid gap-2">
                                        <Label for="draft-version">
                                            Version label
                                        </Label>
                                        <Input
                                            id="draft-version"
                                            v-model="draftForm.version"
                                            placeholder="2026-01"
                                        />
                                        <InputError
                                            :message="draftForm.errors.version"
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="draft-content">
                                            Text (Markdown)
                                        </Label>
                                        <textarea
                                            id="draft-content"
                                            v-model="draftForm.content"
                                            rows="8"
                                            class="rounded-md border border-border bg-surface px-3 py-2 font-mono text-sm"
                                        />
                                        <InputError
                                            :message="draftForm.errors.content"
                                        />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="draft-consent"
                                            :model-value="
                                                draftForm.requiresConsent
                                            "
                                            @update:model-value="
                                                (
                                                    value:
                                                        | boolean
                                                        | 'indeterminate',
                                                ) => {
                                                    draftForm.requiresConsent =
                                                        value === true;
                                                }
                                            "
                                        />
                                        <Label for="draft-consent">
                                            Requires explicit agreement
                                        </Label>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="draft-lawful-basis">
                                            Lawful basis
                                        </Label>
                                        <Select v-model="draftForm.lawfulBasis">
                                            <SelectTrigger
                                                id="draft-lawful-basis"
                                                class="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="consent">
                                                    Consent — the person may
                                                    withdraw at any time
                                                </SelectItem>
                                                <SelectItem value="contract">
                                                    Contract — withdrawal is a
                                                    request
                                                </SelectItem>
                                                <SelectItem
                                                    value="legitimate_interest"
                                                >
                                                    Legitimate interest —
                                                    withdrawal is a request
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            :message="
                                                draftForm.errors.lawfulBasis
                                            "
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        :disabled="draftForm.processing"
                                    >
                                        Create draft
                                    </Button>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </CardContent>
                </Card>

                <Card v-if="canPublish">
                    <CardHeader>
                        <CardTitle>Publish</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p class="text-sm text-text-muted">
                            Publishing supersedes the current published version
                            and, for a required policy, asks everyone to agree
                            again. Save the draft first — publishing takes the
                            stored text.
                        </p>
                        <Button @click="publish">Publish this version</Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
