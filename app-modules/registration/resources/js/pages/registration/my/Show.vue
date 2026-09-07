<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { formatDateTime } from '@/lib/datetime';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';

/**
 * One of the registrant's own registrations. Deliberately a record rather
 * than a console: it shows what was agreed and what state it is in, and
 * offers no action, because every action a registrant has over a confirmed
 * registration — amendment, transfer, cancellation (ADR-0036) — is an
 * organizer-side Action with no self-service path yet. A button that opened
 * a support ticket in disguise would be worse than none.
 *
 * What it cost is not this module's to know either: Commerce contributes it
 * through the `registration.mine.show` slot (ADR-0003 enforcement point 3),
 * which is also how the pay link and the invoice PDF reach the person who
 * owes for them.
 */
type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};
type Registration = Konventix.Registration.Data.MyRegistrationData;

type Billing = {
    recipientName: string;
    email: string | null;
    addressLine: string | null;
    postalCode: string | null;
    city: string | null;
    countryCode: string | null;
    vatId: string | null;
};

type Grant = {
    kind: string;
    role: string;
    quantity: number;
};

const props = defineProps<{
    registration: Registration;
    billing: Billing | null;
    grants: Grant[];
    slots: SlotContribution[];
}>();

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
    pending: 'bg-warning/15 text-warning-contrast',
};

/**
 * The timeline's two timestamps are instants with an offset (ADR-0027) and
 * are shown in the reader's own zone — the event's wall-clock times on the
 * landing page are a different thing and stay printed as promised.
 */
const submittedAt = computed(() =>
    formatDateTime(props.registration.submittedAt),
);
const confirmedAt = computed(() =>
    formatDateTime(props.registration.confirmedAt),
);
</script>

<template>
    <Head :title="props.registration.eventName" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-6">
        <div>
            <Link
                href="/my/registrations"
                class="mb-4 inline-flex items-center gap-2 text-sm text-text-muted hover:text-text"
            >
                <ArrowLeft class="size-4" />
                My registrations
            </Link>

            <Heading
                as="h1"
                :title="props.registration.eventName"
                :description="props.registration.typeName"
            />

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                    :class="
                        statusTone[props.registration.status] ??
                        'bg-subtle text-text'
                    "
                >
                    {{ props.registration.status }}
                </span>
                <span
                    v-if="props.registration.paymentStatus !== 'none'"
                    class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                    :class="
                        paymentTone[props.registration.paymentStatus] ??
                        'bg-subtle text-text'
                    "
                >
                    {{ props.registration.paymentStatus }}
                </span>
            </div>
        </div>

        <section class="flex flex-col gap-2" aria-labelledby="registration-log">
            <h2 id="registration-log" class="text-lg font-medium">Timeline</h2>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                <dt class="text-text-muted">Submitted</dt>
                <dd>
                    <time :datetime="props.registration.submittedAt">
                        {{ submittedAt }}
                    </time>
                </dd>
                <template v-if="props.registration.confirmedAt">
                    <dt class="text-text-muted">Confirmed</dt>
                    <dd>
                        <time :datetime="props.registration.confirmedAt">
                            {{ confirmedAt }}
                        </time>
                    </dd>
                </template>
            </dl>
        </section>

        <section
            v-if="props.grants.length > 0"
            class="flex flex-col gap-2"
            aria-labelledby="registration-grants"
        >
            <h2 id="registration-grants" class="text-lg font-medium">
                What this includes
            </h2>
            <ul class="flex flex-col gap-1 text-sm">
                <li
                    v-for="grant in props.grants"
                    :key="`${grant.kind}-${grant.role}`"
                    class="flex items-baseline gap-2"
                >
                    <span class="font-medium">{{ grant.quantity }}×</span>
                    <span class="capitalize">{{ grant.role }}</span>
                    <span class="text-text-muted">({{ grant.kind }})</span>
                </li>
            </ul>
        </section>

        <template v-for="slot in props.slots" :key="slot.component">
            <component
                :is="resolveRegisteredComponent(slot.component)"
                v-if="resolveRegisteredComponent(slot.component)"
                v-bind="slot.props"
            />
        </template>

        <section
            v-if="props.billing"
            class="flex flex-col gap-2"
            aria-labelledby="registration-billing"
        >
            <h2 id="registration-billing" class="text-lg font-medium">
                Billing details
            </h2>
            <address class="text-sm text-text-muted not-italic">
                <span class="block text-text">
                    {{ props.billing.recipientName }}
                </span>
                <span v-if="props.billing.addressLine" class="block">
                    {{ props.billing.addressLine }}
                </span>
                <span v-if="props.billing.city" class="block">
                    {{ props.billing.postalCode }} {{ props.billing.city }}
                    <template v-if="props.billing.countryCode">
                        ({{ props.billing.countryCode }})
                    </template>
                </span>
                <span v-if="props.billing.email" class="block">
                    {{ props.billing.email }}
                </span>
                <span v-if="props.billing.vatId" class="block">
                    VAT ID {{ props.billing.vatId }}
                </span>
            </address>
        </section>
    </div>
</template>
