<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

// Layout props, the way every other auth page declares itself — the shell is
// applied by the layout resolver rather than imported here. `layout: null`
// opts out of a layout entirely, which is a different thing and not what this
// page wants.
defineOptions({
    layout: {
        title: 'Finish setting up your account',
        description:
            'We still need an email address so you can verify your account and recover it later.',
    },
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="$t('Finish setting up your account')" />

        <p class="text-sm text-text-muted">
            {{
                $t(':provider verified who you are.', {
                    provider: props.providerLabel,
                })
            }}
        </p>

        <Form
            v-bind="store(props.provider)"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">{{ $t('Display name') }}</Label>
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
                    <Label for="username">{{ $t('Username') }}</Label>
                    <Input
                        id="username"
                        name="username"
                        type="text"
                        required
                        autocomplete="username"
                        placeholder="ada-lovelace"
                    />
                    <InputError :message="errors.username" />
                    <p class="text-xs text-text-muted">
                        {{
                            $t(
                                'Your public handle: lowercase letters, numbers and hyphens only, starting and ending with a letter or number.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ $t('Email address') }}</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                    />
                    <InputError :message="errors.email" />
                    <p class="text-xs text-text-muted">
                        {{
                            $t(
                                'We will send you a link to confirm this address.',
                            )
                        }}
                    </p>
                </div>

                <Button type="submit" class="w-full" :disabled="processing">
                    {{ $t('Create account') }}
                </Button>
            </div>

            <p class="text-center text-sm text-text-muted">
                {{ $t('Already have an account?') }}
                <TextLink :href="login()">{{ $t('Log in') }}</TextLink>
                {{
                    $t(
                        '— you can connect :provider from your settings afterwards.',
                        { provider: props.providerLabel },
                    )
                }}
            </p>
        </Form>
    </div>
</template>
