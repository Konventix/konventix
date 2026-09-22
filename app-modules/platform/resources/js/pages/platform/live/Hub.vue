<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatTime } from '@/lib/datetime';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';

/**
 * The live hub (design §7.1): one page about this event, right now. It
 * holds no module imports — every card is a component name the backend
 * registered, resolved through the module component registry; a name that
 * ships nothing renders nothing. The order and the set of cards are the
 * operator's (design §6), decided on the server; this page adds nothing.
 *
 * `onSite` is a badge, never a gate (D-HUB-02). The switcher lists the
 * other running events by name only — switching is a follow-up.
 */
const props = defineProps<{ hub: Konventix.Platform.Data.HubPageData }>();

const windowHint = computed(() =>
    props.hub.event.windowEndsAt
        ? `Zugang bis ${formatTime(props.hub.event.windowEndsAt)}`
        : 'Zugang bis Eventende',
);

const cards = computed(() =>
    props.hub.cards
        .map((card) => ({
            ...card,
            is: resolveRegisteredComponent(card.component),
        }))
        .filter((card) => card.is !== null),
);

const links = computed(() =>
    props.hub.links.map((link) => ({
        ...link,
        is: link.component ? resolveRegisteredComponent(link.component) : null,
    })),
);
</script>

<template>
    <Head :title="hub.event.eventName ?? 'Live'" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                as="h1"
                :title="hub.event.eventName ?? 'Live'"
                :description="[hub.event.dayLabel, windowHint].filter(Boolean).join(' · ')"
            />
            <Badge :variant="hub.event.onSite ? 'default' : 'secondary'" data-test="on-site-badge">
                <span
                    class="mr-1 inline-block size-2 rounded-full"
                    :class="hub.event.onSite ? 'bg-success' : 'bg-text-muted'"
                    aria-hidden="true"
                />
                {{ hub.event.onSite ? 'Vor Ort' : 'Nicht vor Ort' }}
            </Badge>
        </div>

        <nav v-if="hub.events.length > 1" class="flex flex-wrap gap-2" aria-label="Events">
            <span v-for="e in hub.events" :key="e.eventId" class="text-sm text-text-muted">
                {{ e.eventName }}
            </span>
        </nav>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card
                v-for="card in cards"
                :key="card.key"
                class="border-border bg-surface"
                :data-test="`hub-card-${card.key}`"
            >
                <CardHeader>
                    <CardTitle>{{ card.label }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <component :is="card.is" v-bind="card.payload" />
                </CardContent>
            </Card>
        </div>

        <ul v-if="links.length" class="flex flex-wrap gap-3">
            <li v-for="link in links" :key="link.key">
                <component :is="link.is" v-if="link.is" :label="link.label" />
                <Link
                    v-else-if="link.url"
                    :href="link.url"
                    class="text-sm text-accent underline-offset-4 hover:underline"
                >
                    {{ link.label }}
                </Link>
            </li>
        </ul>
    </div>
</template>
