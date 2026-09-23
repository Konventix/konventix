<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { formatDateTime } from '@/lib/datetime';
import { show as showRequest } from '@/routes/support/requests/mine';
import {
    supportCategorySummary,
    supportStateClass,
    supportStateLabel,
} from '../../../components/requests/supportConsole';

/**
 * "My support requests" (design §8.1) — everything this account has
 * reported, newest first.
 *
 * Deliberately **not brand-filtered**, unlike every other `/my/*` list: a
 * person reported something about the platform from wherever they happened
 * to be standing, and a list that hid their own report because they opened
 * the account menu on a different brand host would be a list that loses
 * reports. `MySupportRequestsController` states the reasoning.
 *
 * The state labels and tones come from the console's own
 * `supportConsole.ts` rather than a second copy here, so a reporter and an
 * operator reading the same report read the same word for it.
 *
 * a11y: one `h1` that is the page title, and each row's reference is a real
 * `<Link>` — the card is not a click target with no keyboard equivalent,
 * which is the trap `.ai/rules/js.md` records for the console's tables.
 */
const props = defineProps<{
    requests: Konventix.Support.Data.SupportMyRequestRowData[];
}>();
</script>

<template>
    <Head :title="$t('My support requests')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            :title="$t('My support requests')"
            :description="
                $t('Everything you have reported, and where it stands')
            "
        />

        <p v-if="props.requests.length === 0" class="text-sm text-text-muted">
            {{
                $t(
                    'You have not reported anything yet. The button in the bottom-right corner of any page opens the report form.',
                )
            }}
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="request in props.requests"
                :key="request.id"
                class="flex flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4 sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <Link
                        :href="showRequest(request.id).url"
                        class="font-mono font-medium text-text underline-offset-4 hover:underline"
                    >
                        {{ request.reference }}
                    </Link>
                    <p class="text-sm text-text-muted">
                        {{ supportCategorySummary(request.categories) }}
                    </p>
                    <p class="text-xs text-text-muted">
                        {{ $t('Reported') }}
                        <time :datetime="request.createdAt ?? undefined">
                            {{ formatDateTime(request.createdAt) }}
                        </time>
                        <template v-if="request.lastUpdateAt">
                            · {{ $t('last update') }}
                            <time :datetime="request.lastUpdateAt">
                                {{ formatDateTime(request.lastUpdateAt) }}
                            </time>
                        </template>
                    </p>
                </div>

                <span
                    class="inline-flex shrink-0 self-start rounded-full px-2 py-0.5 text-xs font-medium sm:self-center"
                    :class="supportStateClass(request.state)"
                >
                    {{ supportStateLabel(request.state) }}
                </span>
            </li>
        </ul>
    </div>
</template>
