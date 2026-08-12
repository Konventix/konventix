<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

type EventRow = {
    id: string;
    name: string;
    slug: string;
    state: string;
    startsAt: string;
};

defineProps<{ events: EventRow[] }>();
</script>

<template>
    <Head title="Events" />

    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">Events</h1>

        <div
            class="overflow-x-auto rounded-xl border border-border"
            data-test="events-table"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-text-muted">
                        <th class="p-3 font-medium">Name</th>
                        <th class="p-3 font-medium">Slug</th>
                        <th class="p-3 font-medium">State</th>
                        <th class="p-3 font-medium">Starts</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="events.length">
                        <tr
                            v-for="event in events"
                            :key="event.id"
                            class="border-b border-border last:border-0"
                        >
                            <td class="p-3 font-medium">
                                <Link
                                    :href="`/admin/events/${event.id}`"
                                    class="hover:underline"
                                >
                                    {{ event.name }}
                                </Link>
                            </td>
                            <td class="p-3 text-text-muted">{{ event.slug }}</td>
                            <td class="p-3">
                                <span
                                    class="rounded bg-surface-muted px-1.5 py-0.5 text-xs text-text-muted"
                                >
                                    {{ event.state }}
                                </span>
                            </td>
                            <td class="p-3 text-text-muted">
                                {{ event.startsAt }}
                            </td>
                        </tr>
                    </template>
                    <tr v-else>
                        <td class="p-3 text-text-muted" colspan="4">
                            No events yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
