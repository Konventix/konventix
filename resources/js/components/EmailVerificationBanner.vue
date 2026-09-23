<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import verification from '@/routes/verification';

/**
 * The prompt an unconfirmed address gets on every ordinary page, not only
 * the handful the `verified` route middleware already gates (#81). Most
 * accounts this matters for are Steam signups — Steam asserts no email, so
 * the address they typed at signup is unverified from the first page they
 * land on, and until now nothing outside `security.edit`/`profile.destroy`/
 * the console `dashboard` redirect ever said so.
 *
 * Reads the `emailVerified` shared prop rather than `auth.user`, which stays
 * fixed at Identity's promised five fields (ULID, display name, avatar URL,
 * locale, timezone) — see `HandleInertiaRequests::share()`.
 */
const page = usePage();

const showBanner = computed(() => page.props.emailVerified === false);
</script>

<template>
    <div
        v-if="showBanner"
        role="status"
        class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 bg-warning px-4 py-2 text-center text-sm text-warning-contrast print:hidden"
    >
        <span>{{
            $t('Please confirm your email address to unlock every feature.')
        }}</span>
        <Form
            v-bind="verification.send.form()"
            v-slot="{ processing, wasSuccessful }"
        >
            <Button
                type="submit"
                size="sm"
                variant="outline"
                :disabled="processing || wasSuccessful"
            >
                {{
                    wasSuccessful ? $t('Sent') : $t('Resend verification email')
                }}
            </Button>
        </Form>
    </div>
</template>
