<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/Konventix/Identity/Http/Controllers/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasskeyList from '@/components/PasskeyList.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SessionList from '@/components/SessionList.vue';
import TwoFactorAuthentication from '@/components/TwoFactorAuthentication.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

type Props = {
    hasPassword: boolean;
    passwordRules: string;
    canManageTwoFactor: boolean;
    twoFactorEnabled?: boolean;
    twoFactorConfirming?: boolean;
    requiresConfirmation?: boolean;
    sessions: Konventix.Identity.Data.SessionData[];
    sessionsAvailable: boolean;
    canManagePasskeys: boolean;
    passkeys?: Konventix.Identity.Data.PasskeyData[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Security settings')" />

    <h1 class="sr-only">{{ $t('Security settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="
                props.hasPassword ? $t('Update password') : $t('Set password')
            "
            :description="
                props.hasPassword
                    ? $t(
                          'Ensure your account is using a long, random password to stay secure',
                      )
                    : $t(
                          'This account signs in through a linked account only. Set a password to also sign in with your email address.',
                      )
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div v-if="props.hasPassword" class="grid gap-2">
                <Label for="current_password">{{
                    $t('Current password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    :placeholder="$t('Current password')"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ $t('New password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :placeholder="$t('New password')"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    $t('Confirm password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :placeholder="$t('Confirm password')"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    {{ $t('Save') }}
                </Button>
            </div>
        </Form>

        <div v-if="canManageTwoFactor" class="space-y-6 border-t pt-6">
            <Heading
                variant="small"
                :title="$t('Two-factor authentication')"
                :description="
                    $t('Add an extra layer of security to your account')
                "
            />

            <TwoFactorAuthentication
                :enabled="twoFactorEnabled ?? false"
                :confirming="twoFactorConfirming ?? false"
            />
        </div>

        <div v-if="canManagePasskeys" class="space-y-6 border-t pt-6">
            <Heading
                variant="small"
                :title="$t('Passkeys')"
                :description="
                    $t(
                        'Sign in with your device\'s screen lock or a security key',
                    )
                "
            />

            <PasskeyList :passkeys="passkeys ?? []" />
        </div>

        <div class="space-y-6 border-t pt-6">
            <Heading
                variant="small"
                :title="$t('Sessions')"
                :description="$t('Manage and revoke your active sessions')"
            />

            <SessionList v-if="sessionsAvailable" :sessions="sessions" />
            <p v-else class="text-sm text-muted-foreground" role="status">
                {{
                    $t(
                        'This installation does not keep a list of sessions, so there is nothing to show or revoke here.',
                    )
                }}
            </p>
        </div>
    </div>
</template>
