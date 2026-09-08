<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, RefreshCw } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import {
    acceptConsentWithdrawal as acceptConsentWithdrawalRequest,
    download as downloadArchive,
    index as requestsIndex,
    redispatch as redispatchRequest,
    refuse as refuseRequest,
} from '@/routes/admin/subject-requests';

/**
 * One request and what each module said.
 *
 * The per-module table is the operator's only view into a fan-out that
 * deliberately never touches a foreign table: Compliance knows a module was
 * addressed and what it reported, and nothing else. A module that registered
 * its handler after this request opened shows up as missing rather than
 * silently absent — re-dispatch is what fixes that.
 */
type ResponseRow = {
    module: string;
    state: Konventix.Compliance.Enums.ModuleResponseState;
    respondedAt: string | null;
};

const props = defineProps<{
    request: {
        id: string;
        subjectId: string;
        brandName: string;
        type: Konventix.Compliance.Enums.DataSubjectRequestType;
        state: Konventix.Compliance.Enums.DataSubjectRequestState;
        mode: Konventix.Compliance.Enums.DeletionMode | null;
        deadlineAt: string;
        openedAt: string | null;
        closedAt: string | null;
        reasonCode: string | null;
        archiveExpiresAt: string | null;
        archiveAvailable: boolean;
    };
    responses: ResponseRow[];
    missingModules: string[];
    canHandle: boolean;
    canDownload: boolean;
}>();

const responseClasses: Record<
    Konventix.Compliance.Enums.ModuleResponseState,
    string
> = {
    pending: 'bg-warning/15 text-warning',
    completed: 'bg-success/15 text-success',
    failed: 'bg-danger/15 text-danger',
};

const refusal = useForm({ reasonCode: '' });

function redispatch(): void {
    router.post(
        redispatchRequest(props.request.id).url,
        {},
        { preserveScroll: true },
    );
}

function refuse(): void {
    refusal.post(refuseRequest(props.request.id).url, {
        preserveScroll: true,
        onSuccess: () => refusal.reset(),
    });
}

function acceptConsentWithdrawal(): void {
    router.post(
        acceptConsentWithdrawalRequest(props.request.id).url,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`Data request — ${props.request.type}`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="`${props.request.type} request`"
            :description="`${props.request.subjectId} · ${props.request.brandName}`"
        >
            <Button variant="outline" as-child>
                <Link :href="requestsIndex().url">
                    <ArrowLeft class="size-4" />
                    All requests
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Module responses</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div
                        v-for="response in props.responses"
                        :key="response.module"
                        class="flex items-center justify-between gap-3 rounded-md border border-border p-3"
                    >
                        <span class="font-medium">{{ response.module }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-text-muted">
                                {{
                                    formatDateTime(response.respondedAt) ||
                                    'no answer yet'
                                }}
                            </span>
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="responseClasses[response.state]"
                            >
                                {{ response.state }}
                            </span>
                        </span>
                    </div>

                    <p
                        v-if="props.missingModules.length > 0"
                        class="rounded-md bg-warning/10 p-3 text-sm text-warning"
                        role="status"
                    >
                        Never addressed by this request:
                        {{ props.missingModules.join(', ') }}. These modules
                        registered a handler after it opened — re-dispatch to
                        include them.
                    </p>

                    <p
                        v-if="props.responses.length === 0"
                        class="text-sm text-text-muted"
                    >
                        No module was addressed.
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Request</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2 text-sm">
                        <p>
                            <span class="text-text-muted">State:</span>
                            {{ props.request.state }}
                        </p>
                        <p v-if="props.request.mode">
                            <span class="text-text-muted">Mode:</span>
                            {{ props.request.mode }}
                        </p>
                        <p>
                            <span class="text-text-muted">Opened:</span>
                            {{ formatDateTime(props.request.openedAt) }}
                        </p>
                        <p>
                            <span class="text-text-muted">Deadline:</span>
                            {{ formatCalendarDate(props.request.deadlineAt) }}
                        </p>
                        <p v-if="props.request.closedAt">
                            <span class="text-text-muted">Closed:</span>
                            {{ formatDateTime(props.request.closedAt) }}
                        </p>
                        <p v-if="props.request.reasonCode">
                            <span class="text-text-muted">Refused:</span>
                            {{ props.request.reasonCode }}
                        </p>
                    </CardContent>
                </Card>

                <Card
                    v-if="props.request.archiveAvailable && props.canDownload"
                >
                    <CardHeader>
                        <CardTitle>Export archive</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p class="text-sm text-text-muted">
                            Available until
                            {{
                                formatDateTime(props.request.archiveExpiresAt)
                            }}. This file is every module's answer about one
                            person — treat the download as the disclosure it is.
                        </p>
                        <Button variant="outline" as-child>
                            <a :href="downloadArchive(props.request.id).url">
                                <Download class="size-4" />
                                Download archive
                            </a>
                        </Button>
                    </CardContent>
                </Card>

                <Card v-if="props.canHandle">
                    <CardHeader>
                        <CardTitle>Actions</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="flex flex-col gap-2">
                            <Button variant="outline" @click="redispatch">
                                <RefreshCw class="size-4" />
                                Re-dispatch fan-out
                            </Button>
                            <p class="text-xs text-text-muted">
                                Re-runs every module's handler, not only the
                                failed ones — handlers are idempotent by
                                contract, and Compliance never learns which rows
                                one already touched.
                            </p>
                        </div>

                        <div
                            v-if="
                                props.request.type === 'consent_withdrawal' &&
                                props.request.state !== 'fulfilled'
                            "
                            class="flex flex-col gap-2"
                        >
                            <Button
                                variant="outline"
                                @click="acceptConsentWithdrawal"
                            >
                                Accept withdrawal
                            </Button>
                            <p class="text-xs text-text-muted">
                                Withdraws the named consent and closes this
                                request as fulfilled.
                            </p>
                        </div>

                        <form
                            v-if="props.request.state !== 'fulfilled'"
                            class="flex flex-col gap-2"
                            @submit.prevent="refuse"
                        >
                            <Label for="refusal-reason">
                                Refuse, with legal ground
                            </Label>
                            <Input
                                id="refusal-reason"
                                v-model="refusal.reasonCode"
                                placeholder="art_17_3_b_legal_obligation"
                            />
                            <InputError :message="refusal.errors.reasonCode" />
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="
                                    refusal.processing ||
                                    refusal.reasonCode === ''
                                "
                            >
                                Refuse request
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
