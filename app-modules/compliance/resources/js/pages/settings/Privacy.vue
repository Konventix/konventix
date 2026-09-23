<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { destroy as withdrawConsent } from '@/routes/consents';
import {
    download as downloadArchive,
    store as storeDataRequest,
} from '@/routes/data-requests';
import {
    required as requiredPolicies,
    show as showPolicy,
} from '@/routes/policies';
import { edit } from '@/routes/privacy';
import {
    captureContextLabel,
    requestTypeLabel,
    subjectRequestStateLabel,
} from '../../lib/labels';

/**
 * What this account has agreed to, when, and what is still outstanding.
 *
 * Art. 7(1) GDPR makes the operator able to *demonstrate* consent; the same
 * record is what the data subject is entitled to see, which is why the list
 * keeps withdrawn rows instead of hiding them — "agreed on the 3rd, withdrew
 * on the 9th" is the fact, not "no consent".
 *
 * Export and deletion requests join this page with the DSR console.
 */
type ConsentRow = {
    id: string;
    policyName: string;
    policySlug: string;
    version: string;
    grantedAt: string;
    withdrawnAt: string | null;
    captureContext: string;
    required: boolean;
    selfServiceWithdrawal: boolean;
    withdrawalPending: boolean;
};

type OutstandingRow = {
    id: string;
    name: string;
    slug: string;
    version: string;
};

type RequestRow = {
    id: string;
    type: Konventix.Compliance.Enums.DataSubjectRequestType;
    state: Konventix.Compliance.Enums.DataSubjectRequestState;
    openedAt: string | null;
    deadlineAt: string;
    downloadable: boolean;
    archiveExpiresAt: string | null;
};

const props = defineProps<{
    consents: ConsentRow[];
    outstanding: OutstandingRow[];
    requests: RequestRow[];
    openTypes: string[];
}>();

defineOptions({
    layout: {
        // English key: `defineOptions` is evaluated at import, before the
        // catalog loads, so the shell's breadcrumb renders it through `$t`.
        breadcrumbs: [
            {
                title: 'Privacy settings',
                href: edit(),
            },
        ],
    },
});

function formatDate(value: string): string {
    return formatDateTime(value, { dateStyle: 'medium', timeStyle: 'short' });
}

function withdraw(consent: ConsentRow): void {
    router.delete(withdrawConsent(consent.id).url, { preserveScroll: true });
}

function requestData(type: 'export' | 'deletion'): void {
    router.post(storeDataRequest().url, { type }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Privacy settings')" />

    <h1 class="sr-only">{{ $t('Privacy settings') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Privacy')"
            :description="$t('The documents you have agreed to, and when')"
        />

        <section
            v-if="props.outstanding.length > 0"
            class="flex flex-col gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4"
        >
            <p class="text-sm">
                {{
                    $tChoice(
                        '{1} :count document still needs your agreement.|[2,*] :count documents still need your agreement.',
                        props.outstanding.length,
                    )
                }}
            </p>
            <div>
                <Button as-child size="sm">
                    <Link :href="requiredPolicies().url">{{
                        $t('Review now')
                    }}</Link>
                </Button>
            </div>
        </section>

        <ul v-if="props.consents.length > 0" class="flex flex-col gap-3">
            <li
                v-for="consent in props.consents"
                :key="consent.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
            >
                <div class="flex flex-col gap-1">
                    <Link
                        :href="showPolicy(consent.policySlug).url"
                        class="font-medium hover:text-accent"
                    >
                        {{ consent.policyName }}
                    </Link>
                    <p class="text-xs text-text-muted">
                        {{
                            $t('Version :version · agreed :date · :context', {
                                version: consent.version,
                                date: formatDate(consent.grantedAt),
                                context: captureContextLabel(
                                    consent.captureContext,
                                ),
                            })
                        }}
                    </p>
                    <p
                        v-if="consent.withdrawnAt"
                        class="text-xs text-text-muted"
                    >
                        {{
                            $t('Withdrawn :date', {
                                date: formatDate(consent.withdrawnAt),
                            })
                        }}
                    </p>
                </div>

                <div
                    v-if="!consent.withdrawnAt && consent.withdrawalPending"
                    class="flex flex-col items-end gap-1"
                >
                    <span class="text-xs text-text-muted" role="status">
                        {{ $t('Withdrawal pending') }}
                    </span>
                </div>

                <div
                    v-else-if="!consent.withdrawnAt"
                    class="flex flex-col items-end gap-1"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        @click="withdraw(consent)"
                    >
                        {{
                            consent.selfServiceWithdrawal
                                ? $t('Withdraw consent')
                                : $t('Request withdrawal')
                        }}
                    </Button>
                    <span
                        v-if="consent.required"
                        class="text-xs text-warning"
                        role="status"
                    >
                        {{ $t('Required — you will be asked again') }}
                    </span>
                </div>
            </li>
        </ul>

        <p v-else class="text-sm text-text-muted">
            {{ $t('Nothing recorded yet. Agreements you give appear here.') }}
        </p>

        <Heading
            variant="small"
            :title="$t('Your data')"
            :description="
                $t('Ask for a copy of your data, or for it to be deleted')
            "
        />

        <div class="flex flex-wrap gap-3">
            <Button
                variant="outline"
                :disabled="props.openTypes.includes('export')"
                @click="requestData('export')"
            >
                {{ $t('Request a copy of my data') }}
            </Button>
            <Button
                variant="outline"
                :disabled="props.openTypes.includes('deletion')"
                @click="requestData('deletion')"
            >
                {{ $t('Request deletion') }}
            </Button>
        </div>

        <p class="text-sm text-text-muted">
            {{
                $t(
                    'We answer within 30 days. Deletion anonymizes your records rather than erasing every trace: documents we are legally required to keep — invoices, above all — survive without your personal details attached.',
                )
            }}
        </p>

        <ul v-if="props.requests.length > 0" class="flex flex-col gap-3">
            <li
                v-for="dataRequest in props.requests"
                :key="dataRequest.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
            >
                <div class="flex flex-col gap-1">
                    <span class="font-medium">
                        {{
                            dataRequest.type === 'export'
                                ? $t('Copy of my data')
                                : dataRequest.type === 'deletion'
                                  ? $t('Deletion')
                                  : requestTypeLabel(dataRequest.type)
                        }}
                        — {{ subjectRequestStateLabel(dataRequest.state) }}
                    </span>
                    <span class="text-xs text-text-muted">
                        {{
                            $t('Opened :opened · answer due by :deadline', {
                                opened: formatDateTime(dataRequest.openedAt),
                                deadline: formatCalendarDate(
                                    dataRequest.deadlineAt,
                                ),
                            })
                        }}
                    </span>
                    <span
                        v-if="dataRequest.archiveExpiresAt"
                        class="text-xs text-text-muted"
                    >
                        {{
                            $t('Download available until :date', {
                                date: formatCalendarDate(
                                    dataRequest.archiveExpiresAt,
                                ),
                            })
                        }}
                    </span>
                </div>

                <Button
                    v-if="dataRequest.downloadable"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a :href="downloadArchive(dataRequest.id).url">{{
                        $t('Download')
                    }}</a>
                </Button>
            </li>
        </ul>
    </div>
</template>
