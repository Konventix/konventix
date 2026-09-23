<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { page as competitionPage } from '@/routes/competition';
import { formatEventDateTime } from '../../components/public/eventTime';
import {
    formatLabel,
    statusLabel,
    teamSizeLabel,
} from '../../components/public/labels';
import { usePollWhile } from '../../components/public/usePollWhile';

/**
 * Every tournament of an event (design §10): discipline, format, team size,
 * status, entrants against the limit, start. Public. Polled while one of
 * them is running (§10 "Freshness").
 */
const props = defineProps<{
    page: Konventix.Competition.Data.EventTournamentsPageData;
}>();

usePollWhile(() => props.page.pollSeconds);

function href(slug: string): string {
    return competitionPage({ slug: props.page.event.slug, competition: slug })
        .url;
}
</script>

<template>
    <Head
        :title="$t('Tournaments – :event', { event: props.page.event.name })"
    />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6">
        <Heading
            as="h1"
            :title="$t('Tournaments')"
            :description="props.page.event.name"
        />

        <p
            v-if="props.page.competitions.length === 0"
            class="text-sm text-text-muted"
        >
            {{ $t('No tournaments have been announced for this event yet.') }}
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="competition in props.page.competitions"
                :key="competition.slug"
                class="flex flex-col gap-2 rounded-xl border border-border bg-surface-raised p-4"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="text-lg font-medium text-text">
                        <Link
                            :href="href(competition.slug)"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ competition.name }}
                        </Link>
                    </h2>
                    <span
                        class="text-sm"
                        :class="
                            competition.status === 'cancelled'
                                ? 'text-danger'
                                : competition.status === 'running'
                                  ? 'text-success'
                                  : 'text-text-muted'
                        "
                    >
                        {{ statusLabel(competition.status) }}
                    </span>
                </div>

                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4"
                >
                    <div>
                        <dt class="text-text-muted">{{ $t('Discipline') }}</dt>
                        <dd class="text-text">
                            {{ competition.disciplineName }} ·
                            {{ teamSizeLabel(competition.teamSize) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ $t('Format') }}</dt>
                        <dd class="text-text">
                            {{ formatLabel(competition.format) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ $t('Teams') }}</dt>
                        <dd class="text-text">
                            {{ competition.entrants
                            }}<template v-if="competition.maxEntrants !== null">
                                / {{ competition.maxEntrants }}</template
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">{{ $t('Starts') }}</dt>
                        <dd class="text-text">
                            {{
                                competition.startsAt
                                    ? formatEventDateTime(
                                          competition.startsAt,
                                          props.page.event.timezone,
                                      )
                                    : $t('not set yet')
                            }}
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>
    </div>
</template>
