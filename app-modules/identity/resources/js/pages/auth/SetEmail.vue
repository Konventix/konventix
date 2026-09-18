<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { store } from '@/routes/account-email';

/**
 * What Fortify's verification notice becomes for an account that has no
 * address to verify (#293): the accounts carried over from Eventula that only
 * ever signed in through Steam. There is no "resend" here — nothing was sent
 * — only the form that makes a verification mail possible at all. Once the
 * address is saved the ordinary notice takes over on the same route.
 *
 * A single-field form: the unsaved-changes guard (ADR-0038) is for edit forms
 * with something to lose; leaving this page loses nothing.
 */
defineProps<{
    status?: string;
}>();

defineOptions({
    layout: {
        title: 'Set your email address',
        description:
            'Your account was carried over without an email address. We need one to reach you and so you can recover your account.',
    },
});
</script>

<template>
    <Head title="Set your email address" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-success"
        role="status"
    >
        We have sent a confirmation link to the address you entered.
    </div>

    <div class="flex flex-col gap-6">
        <Form
            v-bind="store.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />
                <InputError id="email-error" :message="errors.email" />
                <p class="text-xs text-text-muted">
                    We will send you a link to confirm this address. Until it is
                    confirmed, orders and settings stay locked.
                </p>
            </div>

            <Button type="submit" :disabled="processing" class="w-full">
                <Spinner v-if="processing" />
                Save and send confirmation link
            </Button>
        </Form>

        <Form v-bind="logout.form()" class="flex flex-col items-center">
            <Button
                type="submit"
                variant="secondary"
                class="w-full"
                data-test="logout-button"
            >
                Log out
            </Button>
        </Form>
    </div>
</template>
