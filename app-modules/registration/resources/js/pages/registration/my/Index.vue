<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { show as showEvent } from '@/routes/event';
import {
    index as myRegistrations,
    show as showRegistration,
} from '@/routes/registration/mine';
import { registrationListSummary } from './listSummary';

/**
 * "What did I sign up for" — the registrant's own list, reached from the
 * account menu. The organizer's console list answers a different question
 * for a different person and shares nothing with this one but the model.
 *
 * The two statuses are shown as two badges because they are two independent
 * facts (SPEC.md "Two independent status fields"): a confirmed registration
 * can still be unpaid, and collapsing them into one word would have to lie
 * about one of them.
 *
 * Cancellations are hidden by default (#239) — history the registrant can do
 * nothing with — and the toggle is a real link to `?show=all` rather than
 * client state: it is keyboard-reachable without any wiring, and a reload or
 * a back button keeps the choice, which a `ref` would silently drop.
 *
 * The event name is its own link to the event's page when the summary knows
 * the slug (Task 17c), so a row carries two links side by side — the event
 * and the registration — rather than one nested in the other, the shape the
 * ticket list settled on.
 */
type Registration = Konventix.Registration.Data.MyRegistrationData;

const props = defineProps<{
    registrations: Registration[];
    /** Whether cancelled registrations are in `registrations` (#239). */
    showAll: boolean;
    /** How many of them there are, shown whichever way the toggle stands. */
    cancelledCount: number;
}>();

const toggleUrl = computed(() =>
    props.showAll
        ? myRegistrations().url
        : myRegistrations({ query: { show: 'all' } }).url,
);

/**
 * What the link just did, for a reader who cannot see the list change
 * (branch review finding 21). It navigates and `preserve-scroll` removes
 * even the visual cue, so without this a screen-reader user hears nothing.
 * Rendered on every state so the region exists before its text does — a
 * `role="status"` inserted together with its content announces nothing —
 * and the page is kept mounted across the toggle (NEW-3) so this is an
 * update to that region rather than a fresh one. The sentence itself lives
 * in `listSummary.ts`, where a unit test can pin that the two states
 * genuinely differ.
 */
const listSummary = computed(() =>
    registrationListSummary(
        props.registrations.length,
        props.cancelledCount,
        props.showAll,
    ),
);

const statusTone: Record<string, string> = {
    confirmed: 'bg-success/15 text-success',
    approved: 'bg-success/15 text-success',
    rejected: 'bg-danger/15 text-danger',
    cancelled: 'bg-danger/15 text-danger',
    submitted: 'bg-subtle text-text',
};

const paymentTone: Record<string, string> = {
    paid: 'bg-success/15 text-success',
    overdue: 'bg-danger/15 text-danger',
    // `--warning-contrast` is the foreground for a *solid* warning fill,
    // not for a 15% tint: on it the badge measured ≈1.3:1 in both themes
    // (branch review finding 23). Its two neighbours already pair the tint
    // with the tone's own text colour.
    pending: 'bg-warning/15 text-warning',
};
</script>

<template>
    <Head title="My registrations" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            title="My registrations"
            description="Everything you have signed up for with this brand"
        />

        <p v-if="props.cancelledCount > 0" role="status" class="sr-only">
            {{ listSummary }}
        </p>

        <p
            v-if="
                props.registrations.length === 0 && props.cancelledCount === 0
            "
            class="text-sm text-text-muted"
        >
            You have not registered for anything yet.
        </p>

        <!--
            #239: the same empty list, and not the same sentence — an account
            whose only registration was cancelled has registered for
            something, and the toggle is what it is being told about.
        -->
        <p
            v-else-if="props.registrations.length === 0"
            class="text-sm text-text-muted"
        >
            Everything you registered for was cancelled — the link below brings
            {{ props.cancelledCount === 1 ? 'it' : 'them' }} back.
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="registration in props.registrations"
                :key="registration.id"
                class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-1">
                    <Link
                        v-if="registration.eventSlug"
                        :href="showEvent(registration.eventSlug).url"
                        class="font-medium text-text hover:text-accent"
                    >
                        {{ registration.eventName }}
                    </Link>
                    <span v-else class="font-medium text-text">
                        {{ registration.eventName }}
                    </span>
                    <span class="text-sm text-text-muted">
                        {{ registration.typeName
                        }}<template v-if="registration.organizationName">
                            — {{ registration.organizationName }}
                        </template>
                    </span>
                    <span class="flex flex-wrap items-center gap-2 pt-1">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="
                                statusTone[registration.status] ??
                                'bg-subtle text-text'
                            "
                        >
                            {{ registration.status }}
                        </span>
                        <span
                            v-if="registration.paymentStatus !== 'none'"
                            class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="
                                paymentTone[registration.paymentStatus] ??
                                'bg-subtle text-text'
                            "
                        >
                            {{ registration.paymentStatus }}
                        </span>
                    </span>
                </span>

                <Link
                    :href="showRegistration(registration.id).url"
                    class="inline-flex min-h-9 items-center gap-1 rounded-md border border-border px-3 text-sm font-medium text-text hover:border-accent"
                >
                    Details
                    <ChevronRight
                        class="size-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ul>

        <p v-if="props.cancelledCount > 0" class="pt-4 text-sm">
            <!--
                `preserve-state` is what makes the live region above work
                (branch review NEW-3): without it Inertia's `swapComponent`
                gives the page a new key, remounts it, and the
                `role="status"` region is destroyed and re-created together
                with its text — which announces nothing, the very shape
                finding 21 was raised about. With the state preserved the
                page stays mounted and the region's text merely changes,
                which is what a live region reports.
            -->
            <Link
                :href="toggleUrl"
                preserve-scroll
                preserve-state
                class="text-text-muted underline underline-offset-4 hover:text-text"
            >
                <template v-if="props.showAll">
                    Hide cancelled registrations
                </template>
                <template v-else>
                    Show all registrations ({{ props.cancelledCount }}
                    cancelled)
                </template>
            </Link>
        </p>
    </div>
</template>
