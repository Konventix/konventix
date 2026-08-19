<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/social/complete';

/**
 * What the provider could not tell us. Steam asserts no email address at all,
 * so an account created this way still needs one before it can be verified,
 * recovered, or written to.
 *
 * The identity itself is not on this page in any form — it lives in the
 * session, verified, and the POST carries only what the person typed. A hidden
 * field naming the provider account would be a field an attacker could change.
 */
const props = defineProps<{
    provider: string;
    providerLabel: string;
    suggestedName: string | null;
}>();

defineOptions({ layout: null });
</script>

<template>
    <AuthLayout
        title="Finish setting up your account"
        :description="`${props.providerLabel} verified who you are. We still need an email address so you can verify your account and recover it later.`"
    >
        <Head title="Finish setting up your account" />

        <Form
            v-bind="store(props.provider)"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Display name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        :default-value="props.suggestedName ?? ''"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                    />
                    <InputError :message="errors.email" />
                    <p class="text-xs text-text-muted">
                        We will send you a link to confirm this address.
                    </p>
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                >
                    Create account
                </Button>
            </div>

            <p class="text-center text-sm text-text-muted">
                Already have an account?
                <TextLink :href="login()">Log in</TextLink>
                — you can connect {{ props.providerLabel }} from your settings
                afterwards.
            </p>
        </Form>
    </AuthLayout>
</template>
