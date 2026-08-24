<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Clock3, CreditCard, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { start as startCheckout } from '@/routes/commerce/pay';

/**
 * Public checkout for an `instant`-settled registration (ADR-0039). This
 * page never decides payment truth — it displays whatever state the
 * webhook has written, and a pending state after returning from the
 * provider simply means the notification has not landed yet.
 */
type Props = {
    registrationId: string;
    order: {
        id: string;
        status: string;
        currency: string;
        totalNet: string;
        totalTax: string;
        totalGross: string;
        lines: { label: string; quantity: number; lineGross: string }[];
    };
    hostedCheckoutAvailable: boolean;
    paymentStatus: string | null;
};

const props = defineProps<Props>();

const starting = ref(false);

function pay(): void {
    starting.value = true;
    router.post(
        startCheckout(props.registrationId).url,
        {},
        { onFinish: () => (starting.value = false) },
    );
}

const paid = computed(() => props.order.status === 'paid');
const awaitingConfirmation = computed(
    () => !paid.value && props.paymentStatus === 'pending',
);
const lastAttemptFailed = computed(
    () =>
        !paid.value &&
        (props.paymentStatus === 'failed' || props.paymentStatus === 'expired'),
);
</script>

<template>
    <Head title="Payment" />

    <div
        class="mx-auto flex w-full max-w-xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <h1 class="text-2xl font-semibold">Payment</h1>

        <Card class="border-border bg-surface">
            <CardHeader>
                <CardTitle>Order summary</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(line, index) in props.order.lines"
                    :key="index"
                    class="flex items-center justify-between"
                >
                    <span>{{ line.quantity }}× {{ line.label }}</span>
                    <span>{{ line.lineGross }} {{ props.order.currency }}</span>
                </div>
                <div
                    class="mt-2 flex items-center justify-between border-t border-border pt-2 font-semibold"
                >
                    <span>Total</span>
                    <span
                        >{{ props.order.totalGross }}
                        {{ props.order.currency }}</span
                    >
                </div>
                <p class="text-xs text-text-muted">
                    includes {{ props.order.totalTax }}
                    {{ props.order.currency }} tax
                </p>
            </CardContent>
        </Card>

        <div
            v-if="paid"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-success"
        >
            <CheckCircle2 class="size-5" />
            <span>Payment received — you are all set.</span>
        </div>

        <div
            v-else-if="awaitingConfirmation"
            role="status"
            class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-text-muted"
        >
            <Clock3 class="size-5" />
            <span>
                Waiting for the payment confirmation from the provider. This
                page can safely be revisited later.
            </span>
        </div>

        <template v-if="!paid">
            <div
                v-if="lastAttemptFailed"
                role="status"
                class="flex items-center gap-3 rounded-md border border-border bg-surface p-4 text-danger"
            >
                <XCircle class="size-5" />
                <span>The last payment attempt did not go through.</span>
            </div>

            <Button
                v-if="props.hostedCheckoutAvailable"
                class="self-start"
                :disabled="starting"
                @click="pay"
            >
                <CreditCard class="size-4" />
                {{
                    lastAttemptFailed || awaitingConfirmation
                        ? 'Try again'
                        : 'Pay now'
                }}
            </Button>

            <p v-else class="text-sm text-text-muted">
                This organizer settles by bank transfer — payment details arrive
                with your confirmation.
            </p>
        </template>
    </div>
</template>
