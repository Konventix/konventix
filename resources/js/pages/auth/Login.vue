<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { isWebAuthnSupported, requestPasskeyAssertion } from '@/lib/webauthn';
import { ceremonyFailureMessage } from '@/lib/webauthnCodec';
import { register } from '@/routes';
import { store } from '@/routes/login';
import passkeyRoutes from '@/routes/passkey';
import { request } from '@/routes/password';
import { redirect as socialRedirect } from '@/routes/social';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

/**
 * The same tick the password form submits, mirrored here so the Steam link
 * — a plain navigation, not a form post — carries it too. `SocialAuthController
 * ::redirect()` reads it off the query string before handing off to Steam
 * (TODO.md: "must pass the login form's own remember checkbox through the
 * redirect"). Steam is the only driver (D-IDN-01), so the provider key is
 * hardcoded the way `SteamProvider::KEY` is everywhere else in this module —
 * there is no list to render.
 */
const remember = ref(false);

const steamRedirectUrl = computed(
    () =>
        socialRedirect('steam', { query: { remember: remember.value ? 1 : 0 } })
            .url,
);

/**
 * Passkeys (bugfix round 2026-09-21, Task 16). The options come from
 * Fortify's `passkey.login-options` as JSON; the assertion goes to
 * `passkey.login` through Inertia, not as JSON, so the server's redirect —
 * the dashboard, or the consent screen when a contract text is outstanding
 * (`ConsentAwarePasskeyLoginResponse`) — is followed like the password
 * form's, and a refusal lands in `errors.credential`. `supported` is read
 * after mount so the SSR markup and the first client render agree.
 */
const page = usePage();
const passkeySupported = ref(false);
const passkeyBusy = ref(false);
const passkeyNotice = ref<string | null>(null);
const passkeyError = computed(
    () => passkeyNotice.value ?? page.props.errors.credential,
);

onMounted(() => {
    passkeySupported.value = isWebAuthnSupported();
});

async function signInWithPasskey(): Promise<void> {
    passkeyNotice.value = null;
    passkeyBusy.value = true;

    try {
        const result = await requestPasskeyAssertion(
            passkeyRoutes.loginOptions.url(),
        );

        if (!result.ok) {
            passkeyNotice.value = ceremonyFailureMessage(result.failure);

            return;
        }

        router.post(passkeyRoutes.login.url(), {
            credential: result.credential,
            remember: remember.value,
        });
    } finally {
        passkeyBusy.value = false;
    }
}
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox
                        id="remember"
                        v-model="remember"
                        name="remember"
                        :tabindex="3"
                    />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div
            v-if="canRegister"
            class="space-x-1 text-center text-sm text-muted-foreground"
        >
            <span>Don't have an account?</span>
            <TextLink :href="register()" :tabindex="6">Sign up</TextLink>
        </div>
    </Form>

    <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t border-border" />
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-background px-2 text-muted-foreground">
                Or continue with
            </span>
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <Button as-child variant="outline" class="w-full" :tabindex="7">
            <!--
                A plain navigation, not a Form: Steam's OpenID round trip starts
                with a redirect, not a POST, and `SocialAuthController::redirect()`
                reads the "remember" tick straight off this link's query string.
                Most migrated accounts sign in this way — this is the button the
                issue's own acceptance criteria call reachable only from the
                settings page today.
            -->
            <a :href="steamRedirectUrl">Sign in with Steam</a>
        </Button>

        <Button
            v-if="passkeySupported"
            type="button"
            variant="outline"
            class="w-full"
            :tabindex="8"
            :disabled="passkeyBusy"
            data-test="passkey-login-button"
            @click="signInWithPasskey"
        >
            <Spinner v-if="passkeyBusy" />
            Sign in with a passkey
        </Button>

        <p
            v-if="passkeyError"
            class="text-center text-sm text-danger"
            role="alert"
            data-test="passkey-login-error"
        >
            {{ passkeyError }}
        </p>
    </div>
</template>
