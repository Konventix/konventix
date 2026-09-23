<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import verification from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verify email',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Email verification')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{
            $t(
                'A new verification link has been sent to the email address you provided during registration.',
            )
        }}
    </div>

    <div class="flex flex-col gap-4">
        <Form
            v-bind="verification.send.form()"
            v-slot="{ processing }"
            class="flex flex-col items-center"
        >
            <Button type="submit" :disabled="processing" class="w-full">
                <Spinner v-if="processing" />
                {{ $t('Resend verification email') }}
            </Button>
        </Form>

        <Form v-bind="logout.form()" class="flex flex-col items-center">
            <Button
                type="submit"
                variant="secondary"
                class="w-full"
                data-test="logout-button"
            >
                {{ $t('Log out') }}
            </Button>
        </Form>
    </div>
</template>
