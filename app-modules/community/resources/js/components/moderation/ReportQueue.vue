<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';

/**
 * The desk's queue of open reports (spec §4.4, D-COM-01), oldest first,
 * each with the line it names — struck through once the desk has removed
 * it. The verbs are the page's; this only says which one was asked for.
 */
defineProps<{
    reports: Konventix.Community.Data.ModerationReportData[];
}>();

const emit = defineEmits<{
    remove: [messageId: string];
    mute: [userId: string, name: string | null];
    resolve: [reportId: string, state: 'resolved' | 'dismissed'];
}>();
</script>

<template>
    <Card class="border-border bg-surface">
        <CardHeader
            ><CardTitle>{{ $t('Open reports') }}</CardTitle></CardHeader
        >
        <CardContent class="flex flex-col gap-4">
            <p v-if="reports.length === 0" class="text-sm text-text-muted">
                {{ $t('Nothing reported.') }}
            </p>
            <div
                v-for="report in reports"
                :key="report.id"
                class="flex flex-col gap-2 border-b border-border pb-4 last:border-b-0 last:pb-0"
            >
                <p class="text-sm text-text">
                    <span class="font-medium">
                        {{
                            report.authorDisplayName ??
                            $t('Unknown participant')
                        }}:
                    </span>
                    <span
                        :class="
                            report.removedAt
                                ? 'text-text-muted line-through'
                                : ''
                        "
                    >
                        {{ report.body }}
                    </span>
                </p>
                <p class="text-xs text-text-muted">
                    {{
                        $t('Reported by :name', {
                            name:
                                report.reporterDisplayName ??
                                $t('an unknown participant'),
                        })
                    }}
                    · {{ formatDateTime(report.reportedAt) }}
                    <template v-if="report.note">
                        · “{{ report.note }}”</template
                    >
                    <template v-if="report.removedAt">
                        · {{ $t('removed') }}</template
                    >
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="!report.removedAt"
                        size="sm"
                        variant="destructive"
                        @click="emit('remove', report.messageId)"
                    >
                        {{ $t('Remove line') }}
                    </Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="
                            emit(
                                'mute',
                                report.authorUserId,
                                report.authorDisplayName,
                            )
                        "
                    >
                        {{ $t('Mute author') }}
                    </Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="emit('resolve', report.id, 'resolved')"
                    >
                        {{ $t('Resolve report') }}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="emit('resolve', report.id, 'dismissed')"
                    >
                        {{ $t('Dismiss report') }}
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
