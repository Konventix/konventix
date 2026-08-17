<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { destroy as withdrawConsent } from '@/routes/consents';
import { required as requiredPolicies, show as showPolicy } from '@/routes/policies';
import { edit } from '@/routes/privacy';

/**
 * What this account has agreed to, when, and what is still outstanding.
 *
 * Art. 7(1) GDPR makes the operator able to *demonstrate* consent; the same
 * record is what the data subject is entitled to see, which is why the list
 * keeps withdrawn rows instead of hiding them — "agreed on the 3rd, withdrew
 * on the 9th" is the fact, not "no consent".
 *
 * Export and deletion requests join this page with the DSR console.
 */
type ConsentRow = {
    id: string;
    policyName: string;
    policySlug: string;
    version: string;
    grantedAt: string;
    withdrawnAt: string | null;
    captureContext: string;
    required: boolean;
};

type OutstandingRow = {
    id: string;
    name: string;
    slug: string;
    version: string;
};

const props = defineProps<{
    consents: ConsentRow[];
    outstanding: OutstandingRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Privacy settings',
                href: edit(),
            },
        ],
    },
});

const dateFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value: string): string {
    return dateFormat.format(new Date(value));
}

function withdraw(consent: ConsentRow): void {
    router.delete(withdrawConsent(consent.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Privacy settings" />

    <h1 class="sr-only">Privacy settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Privacy"
            description="The documents you have agreed to, and when"
        />

        <section
            v-if="props.outstanding.length > 0"
            class="flex flex-col gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4"
        >
            <p class="text-sm">
                {{ props.outstanding.length }} document{{
                    props.outstanding.length === 1 ? '' : 's'
                }}
                still need your agreement.
            </p>
            <div>
                <Button as-child size="sm">
                    <Link :href="requiredPolicies().url">Review now</Link>
                </Button>
            </div>
        </section>

        <ul v-if="props.consents.length > 0" class="flex flex-col gap-3">
            <li
                v-for="consent in props.consents"
                :key="consent.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
            >
                <div class="flex flex-col gap-1">
                    <Link
                        :href="showPolicy(consent.policySlug).url"
                        class="font-medium hover:text-accent"
                    >
                        {{ consent.policyName }}
                    </Link>
                    <p class="text-xs text-text-muted">
                        Version {{ consent.version }} · agreed
                        {{ formatDate(consent.grantedAt) }} ·
                        {{ consent.captureContext }}
                    </p>
                    <p
                        v-if="consent.withdrawnAt"
                        class="text-xs text-text-muted"
                    >
                        Withdrawn {{ formatDate(consent.withdrawnAt) }}
                    </p>
                </div>

                <div
                    v-if="!consent.withdrawnAt"
                    class="flex flex-col items-end gap-1"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        @click="withdraw(consent)"
                    >
                        Withdraw
                    </Button>
                    <span
                        v-if="consent.required"
                        class="text-xs text-warning"
                        role="status"
                    >
                        Required — you will be asked again
                    </span>
                </div>
            </li>
        </ul>

        <p v-else class="text-sm text-text-muted">
            Nothing recorded yet. Agreements you give appear here.
        </p>
    </div>
</template>
