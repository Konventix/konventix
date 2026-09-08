<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { formatDateTime, withZoneName } from '@/lib/datetime';
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
import CheckoutLegalTextGapNotice from '../../../components/CheckoutLegalTextGapNotice.vue';
import { toInstant, versionFormValues } from './versionForm';

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
        /** 'operator' when every brand inherits this text (GitLab #76). */
        scope: 'operator' | 'brand';
        brandName: string;
        slugFrozen: boolean;
    };
    /**
     * The role this policy's slug plays at checkout, null where it plays
     * none (#200) — and the whole catalogue, so the editor can say what
     * the alternative is.
     */
    checkoutRole: Konventix.Compliance.Data.CheckoutLegalTextRoleData | null;
    checkoutRoles: Konventix.Compliance.Data.CheckoutLegalTextRoleData[];
    /**
     * Brands whose checkout resolves neither text (#222) — this policy's
     * own brand, or every brand for an operator-wide text.
     */
    checkoutGaps: Konventix.Compliance.Data.CheckoutLegalTextGapData[];
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

const versionForm = useForm(versionFormValues(props.selected));

/**
 * Inertia reuses this component across a redirect that renders the same page,
 * so `setup()` — and with it `useForm`'s initial values — runs once for the
 * whole session. Creating a draft redirects to the new draft's editor, and
 * without this the form still held the *previous* version's label and wrote it
 * onto the new row: a violation of `policy_versions(policy_id, version)`, a
 * 500, and the draft standing anyway.
 *
 * Only the identity of the selection is watched. Re-seeding on every prop
 * change would throw away edits the operator has not saved yet.
 */
watch(
    () => props.selected?.id ?? null,
    () => {
        versionForm.defaults(versionFormValues(props.selected));
        versionForm.reset();
        versionForm.clearErrors();
    },
);

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
            effectiveAt: toInstant(data.effectiveAt),
        }))
        .put(updateVersion(props.selected.id).url, { preserveScroll: true });
}

function createDraft(): void {
    draftForm
        .transform((data) => ({
            ...data,
            effectiveAt: toInstant(data.effectiveAt),
        }))
        .post(storeVersion(props.policy.id).url, {
            onSuccess: () => {
                draftForm.reset();
                draftOpen.value = false;
            },
        });
}

/**
 * Publishing takes the date *stored* on the draft, never the unsaved field:
 * publishing already takes the stored text, and sending an edited date with it
 * published a version under a date that was never saved anywhere.
 */
function publish(): void {
    if (props.selected === null) {
        return;
    }

    router.post(
        publishVersion(props.selected.id).url,
        { effectiveAt: props.selected.effectiveAt },
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
            :description="
                props.policy.scope === 'operator'
                    ? `${props.policy.slug} · ${props.policy.brandName} — every brand inherits this text unless it publishes its own`
                    : `${props.policy.slug} · ${props.policy.brandName}`
            "
        >
            <Button variant="outline" as-child>
                <Link :href="policiesIndex().url">
                    <ArrowLeft class="size-4" />
                    All policies
                </Link>
            </Button>
        </AdminPageHeader>

        <!--
            #222: the warning follows the operator onto the page they fix it
            on. `policiesUrl` is null here — the create dialog lives on the
            list, and this page's own editor is the other way to close the
            gap.
        -->
        <CheckoutLegalTextGapNotice
            :gaps="props.checkoutGaps"
            :roles="props.checkoutRoles"
            :policies-url="policiesIndex().url"
        />

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
                                <!--
                                    #200: the slug is what the checkout looks
                                    for, so the editor says which role this
                                    one plays — or that it plays none.
                                -->
                                <p
                                    v-if="props.checkoutRole"
                                    class="text-xs text-text-muted"
                                >
                                    <span class="font-medium text-text">
                                        Checkout role:
                                        {{ props.checkoutRole.name }}
                                    </span>
                                    {{ props.checkoutRole.description }}
                                </p>
                                <p v-else class="text-xs text-text-muted">
                                    The checkout never asks for this text — it
                                    resolves only
                                    <code>{{
                                        props.checkoutRoles
                                            .map((role) => role.slug)
                                            .join(', ')
                                    }}</code
                                    >.
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
                                <time
                                    v-if="version.effectiveAt"
                                    :datetime="version.effectiveAt"
                                    >{{
                                        formatDateTime(
                                            version.effectiveAt,
                                            withZoneName,
                                        )
                                    }}</time
                                >
                                <template v-else>no date</template>
                                · {{ version.consents }} agreement{{
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
                            stored text and the stored date.
                        </p>
                        <p
                            v-if="props.selected?.effectiveAt"
                            class="text-sm text-text-muted"
                        >
                            Effective from
                            <time
                                class="font-medium text-text"
                                :datetime="props.selected.effectiveAt ?? ''"
                                >{{
                                    formatDateTime(
                                        props.selected.effectiveAt,
                                        withZoneName,
                                    )
                                }}</time
                            >
                            — until then the text is not served under its public
                            URL.
                        </p>
                        <p
                            v-else
                            class="rounded-md bg-warning/10 p-3 text-sm text-warning"
                            role="status"
                        >
                            This draft has no effective date. Set
                            <strong>Effective from</strong> and save the draft —
                            a published text has to say from when it binds.
                        </p>
                        <Button
                            :disabled="!props.selected?.effectiveAt"
                            @click="publish"
                        >
                            Publish this version
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
