<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as supportRequestsIndex } from '@/routes/admin/support/requests';
import {
    supportCategorySummary,
    supportStateClass,
    supportStateLabel,
} from '../../../components/requests/supportConsole';
import SupportContextCard from '../../../components/requests/SupportContextCard.vue';
import SupportThread from '../../../components/requests/SupportThread.vue';
import SupportTriageCard from '../../../components/requests/SupportTriageCard.vue';

/**
 * One report (design §9.2).
 *
 * Opening this page is itself a write: the first staff member to look moves
 * the report from `received` to `seen`, through the same Action the buttons
 * use, so it is audited and the unseen-age gauge stops running. That
 * happens on the server before this renders — there is nothing to do here.
 *
 * The page is three panels and holds no logic of its own: the thread, the
 * technical context with its two log deep links, and the triage controls.
 * Each is its own component, which is what keeps this file a layout
 * (`.ai/rules/pages.md`, CLAUDE_extra's ~300-line guideline).
 *
 * `reporterContact` is present only for a holder of
 * `support.view-reporter-contact`, and the server recorded the disclosure
 * when it filled it (§10.8). Everyone else gets the masked label, which is
 * what the inbox shows too.
 */
const props = defineProps<{
    detail: Konventix.Support.Data.SupportRequestDetailData;
    assignees: string[];
    currentUserId: string | null;
    canTriage: boolean;
}>();

const request = computed(() => props.detail.request);
</script>

<template>
    <Head
        :title="
            $t('Support request :reference', { reference: request.reference })
        "
    />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="$t('Report :reference', { reference: request.reference })"
            :description="`${supportCategorySummary(request.categories)} · ${props.detail.brandLabel}`"
        >
            <Button variant="outline" as-child>
                <Link :href="supportRequestsIndex().url">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ $t('All reports') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <SupportThread
                    :request-id="request.id"
                    :message="request.message"
                    :created-at="request.createdAt"
                    :reporter-label="request.reporterLabel"
                    :messages="request.messages"
                    :can-triage="props.canTriage"
                />

                <SupportContextCard
                    :context="request.context"
                    :brand-label="props.detail.brandLabel"
                    :page-logs-url="props.detail.pageLogsUrl"
                    :submit-logs-url="props.detail.submitLogsUrl"
                />
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ $t('Reporter') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2 text-sm">
                        <p>
                            <span class="text-text-muted">{{
                                $t('Shown as:')
                            }}</span>
                            {{ request.reporterLabel }}
                        </p>
                        <p v-if="props.detail.reporterContact">
                            <span class="text-text-muted">{{
                                $t('Contact:')
                            }}</span>
                            <span class="font-mono text-xs break-all">
                                {{ props.detail.reporterContact }}
                            </span>
                        </p>
                        <p v-else class="text-xs text-text-muted">
                            {{
                                $t(
                                    'Every look at the unmasked address is recorded. Seeing it needs the permission',
                                )
                            }}
                            <code>{{ 'support.view-reporter-contact' }}</code
                            >.
                        </p>
                        <p class="text-xs text-text-muted">
                            {{
                                request.contactConsent
                                    ? $t(
                                          'Agreed to be contacted about this report.',
                                      )
                                    : $t(
                                          'Did not agree to be contacted; a reply mail is not sent.',
                                      )
                            }}
                        </p>
                        <p>
                            <span class="text-text-muted">{{
                                $t('State:')
                            }}</span>
                            <span
                                class="ml-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="supportStateClass(request.state)"
                            >
                                {{ supportStateLabel(request.state) }}
                            </span>
                        </p>
                        <p v-if="request.externalReference" class="text-xs">
                            <span class="text-text-muted">{{
                                $t('External:')
                            }}</span>
                            {{ request.externalReference }}
                        </p>
                    </CardContent>
                </Card>

                <SupportTriageCard
                    v-if="props.canTriage"
                    :request-id="request.id"
                    :state="request.state"
                    :allowed-next="props.detail.allowedNext"
                    :assigned-to-user-id="request.assignedToUserId"
                    :assignees="props.assignees"
                    :current-user-id="props.currentUserId"
                    :is-known-issue="request.isKnownIssue"
                    :known-issue-notice="request.knownIssueNotice"
                    :external-reference="request.externalReference"
                />
            </div>
        </div>
    </div>
</template>
