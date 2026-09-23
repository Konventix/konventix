<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    LayoutGrid,
    MessagesSquare,
    Pencil,
    RefreshCw,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { edit as editAgenda } from '@/routes/admin/events/agenda';
import { index as attendeesIndex } from '@/routes/admin/events/attendees';
import { edit as editHubLayout } from '@/routes/admin/events/hub';
import { show as showShoutbox } from '@/routes/admin/events/shoutbox';

/**
 * Composes Event's own header with contributions from other modules'
 * `SlotRegistry` entries for the `event.admin.dashboard` slot (ADR-0003
 * enforcement point 3, docs/decisions/0003 "slots are an activation gate
 * point"). Contributions resolve through the registered-component
 * convention (`<module>/<path>` — see lib/moduleComponents.ts), so this
 * page never imports a module's card by guessing a path; it only resolves
 * the string contract each module's ServiceProvider declares in its own
 * `registerSlots()`.
 *
 * "Manual refresh" (LanCore's `Dashboard.vue` pattern) bumps `refreshToken`,
 * which each card watches to re-fetch its own endpoint — there is no shared
 * Inertia prop to reload, since each card owns its own client-side fetch.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type EventHeader = {
    id: string;
    name: string;
    state: string;
    startsAt: string;
    endsAt: string;
    timezone: string;
};

const props = defineProps<{
    event: EventHeader;
    transitions: string[];
    /**
     * Which gateable modules this event's brand has switched on, for the
     * links *out* of this page. A card rendered inside it comes through
     * `slots` and is gated server-side; a link cannot, because
     * `SlotRegistry` gates on the ambient brand and the operator admin host
     * resolves none (ADR-0029). The controller asks `isActiveForBrand()`
     * instead and sends the answer here (ADR-0003, enforcement point 3).
     */
    modules: Record<string, boolean>;
    /**
     * Links out of this page that a capability opens: shown only to an
     * account that holds it, so the button never leads to a 403.
     */
    can: { attendees: boolean };
    slots: SlotContribution[];
}>();

/**
 * The server sends exactly the transitions legal from the current state;
 * each maps to one lifecycle endpoint (one Action each — the guards live
 * there). Cancel additionally asks for a reason code.
 */
const transitionLabels: Record<string, string> = {
    announce: 'Announce',
    publish: 'Publish',
    unpublish: 'Unpublish',
    start: 'Start',
    conclude: 'Conclude',
    cancel: 'Cancel event',
    archive: 'Archive',
};

function runTransition(transition: string): void {
    const payload: Record<string, string> = {};

    if (transition === 'cancel') {
        const reason = window.prompt('Reason code for the cancellation:');

        if (!reason) {
            return;
        }

        payload.reason_code = reason;
    }

    router.post(`/admin/events/${props.event.id}/${transition}`, payload, {
        preserveScroll: true,
    });
}

const stateBadgeClass: Record<string, string> = {
    draft: 'bg-surface-muted text-text-muted',
    announced: 'bg-accent/15 text-accent',
    published: 'bg-success/15 text-success',
    running: 'bg-success/15 text-success',
    concluded: 'bg-surface-muted text-text-muted',
    archived: 'bg-surface-muted text-text-muted',
    cancelled: 'bg-danger/15 text-danger',
};

const refreshToken = ref(0);
const refreshing = ref(false);

function refresh(): void {
    refreshing.value = true;
    refreshToken.value += 1;

    // Cards resolve their own fetch promise; this button only needs to show
    // "something happened" briefly rather than track every card's request.
    window.setTimeout(() => {
        refreshing.value = false;
    }, 400);
}
</script>

<template>
    <!--
    datetime-raw: event.startsAt
    datetime-raw: event.endsAt
    datetime-raw: props.event.startsAt
    datetime-raw: props.event.endsAt
    An event time is the organizer's wall clock plus a zone label (ADR-0027),
    not an instant: it is printed exactly as promised, in the zone the label
    names, and must not be converted to the reader's own.
-->
    <Head :title="event.name" />

    <div class="flex flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-semibold text-text">
                        {{ event.name }}
                    </h1>
                    <span
                        class="rounded px-1.5 py-0.5 text-xs"
                        :class="
                            stateBadgeClass[event.state] ??
                            'bg-surface-muted text-text-muted'
                        "
                    >
                        {{ event.state }}
                    </span>
                </div>
                <p class="text-sm text-text-muted">
                    {{ event.startsAt }} – {{ event.endsAt }} ({{
                        event.timezone
                    }})
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    v-for="transition in props.transitions"
                    :key="transition"
                    :variant="
                        transition === 'cancel' ? 'destructive' : 'secondary'
                    "
                    size="sm"
                    @click="runTransition(transition)"
                >
                    {{ transitionLabels[transition] ?? transition }}
                </Button>
                <Link :href="`/admin/events/${props.event.id}/edit`">
                    <Button variant="outline" size="sm">
                        <Pencil class="size-4" />
                        Edit
                    </Button>
                </Link>
                <Button as-child variant="secondary" size="sm">
                    <Link :href="editHubLayout(props.event.id).url">
                        <LayoutGrid class="size-4" />
                        Live hub
                    </Link>
                </Button>
                <!-- Agenda's editor. A plain link, not a slot
                     contribution: this is a way *to* another module's page,
                     not a card rendered inside this one. Hidden when the
                     brand has Agenda off, because that editor then does not
                     exist (ADR-0003) and a button onto a 404 is not a
                     gate. -->
                <Button
                    v-if="props.modules.agenda"
                    as-child
                    variant="secondary"
                    size="sm"
                >
                    <Link :href="editAgenda(props.event.id).url">
                        <CalendarClock class="size-4" />
                        Programme
                    </Link>
                </Button>
                <!-- Community's shoutbox desk, on the same terms as the
                     programme editor above: a way to another module's page,
                     hidden when the brand has Community off. -->
                <Button
                    v-if="props.modules.community"
                    as-child
                    variant="secondary"
                    size="sm"
                >
                    <Link :href="showShoutbox(props.event.id).url">
                        <MessagesSquare class="size-4" />
                        Shoutbox
                    </Link>
                </Button>
                <!-- Admission's attendee list (#329). No module flag:
                     Admission is Core and cannot be switched off for a
                     brand, so the page always exists — but only an account
                     holding `admission.check-in-attendees` may open it. -->
                <Button
                    v-if="props.can.attendees"
                    as-child
                    variant="secondary"
                    size="sm"
                >
                    <Link :href="attendeesIndex(props.event.id).url">
                        <Users class="size-4" />
                        Attendees
                    </Link>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCw
                        class="size-4"
                        :class="{ 'animate-spin': refreshing }"
                    />
                    Refresh
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <template
                v-for="slot in slots"
                :key="`${slot.module}-${slot.component}`"
            >
                <component
                    :is="resolveRegisteredComponent(slot.component)"
                    v-if="resolveRegisteredComponent(slot.component)"
                    :event-id="event.id"
                    :refresh-token="refreshToken"
                    v-bind="slot.props"
                />
            </template>
        </div>
    </div>
</template>
