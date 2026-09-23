<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import twoFactor from '@/routes/two-factor';

defineOptions({
    layout: {
        title: 'Two-factor authentication',
        description: 'Confirm access to your account',
    },
});

const usingRecoveryCode = ref(false);

function toggleRecoveryCode(): void {
    usingRecoveryCode.value = !usingRecoveryCode.value;
}
</script>

<template>
    <Head :title="$t('Two-factor authentication')" />

    <Form
        v-bind="twoFactor.login.store.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div v-if="!usingRecoveryCode" class="grid gap-2">
            <Label for="code">{{ $t('Authentication code') }}</Label>
            <p class="text-sm text-muted-foreground">
                {{ $t('Enter the code from your authenticator app.') }}
            </p>
            <Input
                id="code"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                :placeholder="$t('Code')"
            />
            <InputError :message="errors.code" />
        </div>

        <div v-else class="grid gap-2">
            <Label for="recovery_code">{{ $t('Recovery code') }}</Label>
            <Input
                id="recovery_code"
                name="recovery_code"
                autofocus
                autocomplete="one-time-code"
                :placeholder="$t('Recovery code')"
            />
            <InputError :message="errors.recovery_code" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            {{ $t('Continue') }}
        </Button>

        <button
            type="button"
            class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            @click="toggleRecoveryCode"
        >
            {{
                usingRecoveryCode
                    ? $t('Use an authentication code instead')
                    : $t('Use a recovery code instead')
            }}
        </button>
    </Form>
</template>
