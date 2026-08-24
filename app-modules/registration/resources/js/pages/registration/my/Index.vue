<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

/**
 * "What did I sign up for" — the registrant's own list, reached from the
 * account menu. The organizer's console list answers a different question
 * for a different person and shares nothing with this one but the model.
 *
 * The two statuses are shown as two badges because they are two independent
 * facts (SPEC.md "Two independent status fields"): a confirmed registration
 * can still be unpaid, and collapsing them into one word would have to lie
 * about one of them.
 */
type Registration = Konventix.Registration.Data.MyRegistrationData;

const props = defineProps<{
    registrations: Registration[];
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
</script>

<template>
    <Head title="My registrations" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            title="My registrations"
            description="Everything you have signed up for with this brand"
        />

        <p
            v-if="props.registrations.length === 0"
            class="text-sm text-text-muted"
        >
            You have not registered for anything yet.
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="registration in props.registrations"
                :key="registration.id"
            >
                <Link
                    :href="`/my/registrations/${registration.id}`"
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-medium text-text">
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

                    <ChevronRight class="size-4 shrink-0 text-text-muted" />
                </Link>
            </li>
        </ul>
    </div>
</template>
