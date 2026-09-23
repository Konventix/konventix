<script setup lang="ts">
import { formatDateTime } from '@/lib/datetime';

type HistoryRow = Konventix.Admission.Data.CheckInHistoryRowData;

/**
 * A ticket's full check-in history (attendee-list design §4.4), oldest
 * first: every scan and console check-in, accepted or rejected, with the
 * reason as a sentence and who recorded it — the door device, or the admin
 * at the console.
 */
defineProps<{ history: HistoryRow[] }>();
</script>

<template>
    <p v-if="history.length === 0" class="text-sm text-text-muted">
        This ticket has never been scanned or checked in.
    </p>
    <div v-else class="overflow-x-auto">
        <table class="w-full text-sm" data-test="ticket-history">
            <thead>
                <tr class="border-b border-border text-left text-text-muted">
                    <th scope="col" class="py-2 pr-4 font-medium">Time</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Direction</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Result</th>
                    <th scope="col" class="py-2 pr-4 font-medium">
                        Checkpoint
                    </th>
                    <th scope="col" class="py-2 font-medium">By</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in history"
                    :key="row.id"
                    class="border-b border-border last:border-0"
                >
                    <td class="py-2 pr-4 whitespace-nowrap">
                        {{ formatDateTime(row.scannedAt) }}
                    </td>
                    <td class="py-2 pr-4">
                        {{ row.direction === 'in' ? 'In' : 'Out' }}
                    </td>
                    <td class="py-2 pr-4">
                        <span
                            v-if="row.result === 'accepted'"
                            class="text-success"
                        >
                            Accepted
                        </span>
                        <span v-else class="text-danger">
                            Rejected<template v-if="row.reason">
                                — {{ row.reason }}</template
                            >
                        </span>
                    </td>
                    <td class="py-2 pr-4">
                        {{ row.checkpointName ?? '—' }}
                        <span class="text-xs text-text-muted">
                            ({{ row.source }})
                        </span>
                    </td>
                    <td class="py-2">
                        <template v-if="row.source === 'console'">
                            {{ row.actorName ?? 'An admin' }}
                        </template>
                        <template v-else>
                            {{ row.deviceLabel ?? 'Door device' }}
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
