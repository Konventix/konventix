<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';
import { index as registrationsIndex } from '@/routes/admin/registrations';
import RegistrationEntryConditionsPanel from '../../../components/registrations/RegistrationEntryConditionsPanel.vue';
import {
    paymentStatusLabel,
    registrationStatusLabel,
} from '../../../lib/labels';

/**
 * One registration, and the declarations it rests on (ADR-0066 §9.1).
 *
 * The page is deliberately narrow: the list is still where an operator
 * approves, exports and reduces. What needed a screen of its own is the
 * entry-condition list, because after D-REG-19 it has three states to show —
 * declared, *not asserted* (a condition added to the type afterwards, no row
 * at all) and *no longer required* (a condition the type has since dropped,
 * whose declaration stays because it is evidence).
 */
defineProps<{
    registration: {
        id: string;
        registrant: string;
        registrantEmail: string | null;
        organization: string | null;
        typeName: string;
        eventName: string;
        status: string;
        paymentStatus: string;
        quantity: number;
        submittedAt: string;
    };
    entryConditions: Konventix.Registration.Data.EntryConditionAssertionData[];
    canVerifyEntryConditions: boolean;
    reasonCodes: string[];
}>();
</script>

<template>
    <Head
        :title="$t(':name — Registration', { name: registration.registrant })"
    />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="registration.registrant"
            :description="`${registration.typeName} · ${registration.eventName}`"
        >
            <Button variant="outline" as-child>
                <Link :href="registrationsIndex().url">
                    <ArrowLeft class="size-4" />
                    {{ $t('All registrations') }}
                </Link>
            </Button>
        </AdminPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>{{ $t('Entry conditions') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <RegistrationEntryConditionsPanel
                        :registration-id="registration.id"
                        :rows="entryConditions"
                        :can-verify="canVerifyEntryConditions"
                        :reason-codes="reasonCodes"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ $t('The act') }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-2 text-sm">
                    <p
                        v-if="registration.registrantEmail"
                        class="text-text-muted"
                    >
                        {{ registration.registrantEmail }}
                    </p>
                    <p v-if="registration.organization" class="text-text-muted">
                        {{ registration.organization }}
                    </p>
                    <p>
                        {{ $t('Status:') }}
                        <span class="font-medium">{{
                            registrationStatusLabel(registration.status)
                        }}</span>
                    </p>
                    <p>
                        {{ $t('Payment:') }}
                        <span class="font-medium">{{
                            paymentStatusLabel(registration.paymentStatus)
                        }}</span>
                    </p>
                    <p>
                        {{ $t('Units:') }}
                        <span class="font-medium tabular-nums">{{
                            registration.quantity
                        }}</span>
                    </p>
                    <p class="text-text-muted">
                        {{ $t('Submitted') }}
                        <time :datetime="registration.submittedAt">{{
                            formatDateTime(registration.submittedAt)
                        }}</time>
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
