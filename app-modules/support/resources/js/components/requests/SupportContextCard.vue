<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';

/**
 * What the browser sent with the report (design §5, rendered per §9.2).
 *
 * A definition list rather than a table: these are name/value pairs and
 * `<dl>` is what says so to a screen reader. The two correlation ids get
 * the emphasis, because they are the whole reason this panel beats an
 * e-mail address — one leads to the log lines of the page that misbehaved,
 * the other to the POST that reported it (ADR-0056), and conflating them
 * would make every report point at itself.
 *
 * "Find in logs" appears only when the deployment has configured a Logs
 * dashboard (`config('support.logs.correlation_url')`, resolved on the
 * server). No configuration, no button — a link to nowhere is worse than no
 * link, and the id is still there to be copied by hand.
 *
 * The five recent visits are the reporter's own path to the problem. They
 * are shown as they were sent, because a URL is not a timestamp and the
 * order is the information.
 */
const props = defineProps<{
    context: Konventix.Support.Data.SupportContextData;
    brandLabel: string;
    pageLogsUrl: string | null;
    submitLogsUrl: string | null;
}>();

/**
 * The two ids, side by side and labelled by what each one leads to. Built
 * here rather than written twice in the template: they differ in three
 * strings and nothing else, and the pair is the point.
 */
const correlationIds = computed(() => [
    {
        key: 'page',
        label: 'Page correlation id',
        value: props.context.pageCorrelationId,
        logs: props.pageLogsUrl,
        hint: 'The page that misbehaved.',
    },
    {
        key: 'submit',
        label: 'Submit correlation id',
        value: props.context.submitCorrelationId,
        logs: props.submitLogsUrl,
        hint: 'The request that filed this report.',
    },
]);

/** The clipboard is a convenience; a failed write is not worth a message. */
function copy(value: string | null): void {
    if (value === null) {
        return;
    }

    void navigator.clipboard?.writeText(value).catch(() => undefined);
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>What the browser sent</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4 text-sm">
            <div
                v-for="id in correlationIds"
                :key="id.key"
                class="flex flex-col gap-1 rounded-md border border-border p-3"
            >
                <span class="text-xs text-text-muted">{{ id.label }}</span>
                <span class="font-mono text-xs break-all">
                    {{ id.value ?? '—' }}
                </span>
                <span class="text-xs text-text-muted">{{ id.hint }}</span>
                <div v-if="id.value" class="flex flex-wrap gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="copy(id.value)"
                    >
                        Copy
                    </Button>
                    <Button v-if="id.logs" variant="outline" size="sm" as-child>
                        <a :href="id.logs" target="_blank" rel="noopener">
                            <ExternalLink class="size-4" aria-hidden="true" />
                            Find in logs
                        </a>
                    </Button>
                </div>
            </div>

            <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-2">
                <dt class="text-text-muted">Brand</dt>
                <dd>{{ props.brandLabel }}</dd>

                <dt class="text-text-muted">Route</dt>
                <dd class="font-mono text-xs break-all">
                    {{ props.context.route ?? '—' }}
                </dd>

                <dt class="text-text-muted">URL</dt>
                <dd class="text-xs break-all">
                    {{ props.context.url ?? '—' }}
                </dd>

                <dt class="text-text-muted">Release</dt>
                <dd class="tabular-nums">{{ props.context.release ?? '—' }}</dd>

                <dt class="text-text-muted">Previous page</dt>
                <dd class="text-xs break-all">
                    {{ props.context.previousUrl ?? '—' }}
                </dd>

                <dt class="text-text-muted">Viewport</dt>
                <dd class="tabular-nums">
                    {{
                        props.context.viewport
                            ? `${props.context.viewport.width} × ${props.context.viewport.height} @ ${props.context.viewport.dpr}×`
                            : '—'
                    }}
                </dd>

                <dt class="text-text-muted">Sent</dt>
                <dd>
                    <time :datetime="props.context.receivedAt ?? undefined">
                        {{ formatDateTime(props.context.receivedAt) || '—' }}
                    </time>
                </dd>

                <dt class="text-text-muted">Last script error</dt>
                <dd class="text-xs break-all">
                    {{ props.context.lastClientError ?? 'None' }}
                </dd>
            </dl>

            <div class="flex flex-col gap-1">
                <h3 class="text-xs font-medium text-text-muted">
                    The last five pages before the report
                </h3>
                <ol
                    v-if="props.context.recentVisits.length > 0"
                    class="flex flex-col gap-1"
                >
                    <li
                        v-for="(visit, index) in props.context.recentVisits"
                        :key="`${visit.url}-${index}`"
                        class="text-xs break-all text-text-muted"
                    >
                        {{ visit.url }}
                    </li>
                </ol>
                <p v-else class="text-xs text-text-muted">
                    Nothing recorded — the report came from the first page of
                    the visit.
                </p>
            </div>
        </CardContent>
    </Card>
</template>
