<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import password from '@/routes/password';

defineOptions({
    layout: {
        title: 'Confirm your password',
        description:
            'This is a secure area of the application. Please confirm your password before continuing.',
    },
});
</script>

<template>
    <Head :title="$t('Confirm password')" />

    <Form
        v-bind="password.confirm.store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="password">{{ $t('Password') }}</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autofocus
                autocomplete="current-password"
                :placeholder="$t('Password')"
            />
            <InputError :message="errors.password" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            {{ $t('Confirm') }}
        </Button>
    </Form>
</template>
