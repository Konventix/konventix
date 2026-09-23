<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/datetime';
import { createPasskey, isWebAuthnSupported } from '@/lib/webauthn';
import { ceremonyFailureMessage } from '@/lib/webauthnCodec';
import passkeyRoutes from '@/routes/passkey';

/**
 * The "Passkeys" section of the Security settings page (bugfix round
 * 2026-09-21, Task 16): the account's passkeys, a form to add one, and a
 * Remove per row. Adding and removing sit behind Laravel's `password.confirm`
 * (config/fortify-options.php), the same gate as two-factor enrolment.
 *
 * Adding is two steps. `createPasskey()` fetches the options as JSON and
 * runs the browser ceremony; the resulting credential is then posted
 * through Inertia so the server's `back()` refreshes the list and its
 * validation errors land in `errors.credential` like any form's. When the
 * options fetch answers 423 — the password has not been confirmed recently
 * — an *empty* Inertia POST to the same store route is made on purpose: the
 * middleware turns that into the confirm-password page with this page as
 * the way back (`Redirector::guest()` records the referrer for a non-GET),
 * which a JSON fetch cannot do. After confirming, the person clicks Add
 * again, exactly as with "Enable two-factor authentication".
 */
const props = defineProps<{
    passkeys: Konventix.Identity.Data.PasskeyData[];
}>();

const page = usePage();
const name = ref('');
const busy = ref(false);
const notice = ref<string | null>(null);
// Read after mount so the server-rendered markup and the first client render
// agree (no `navigator` on the SSR side).
const supported = ref(false);

onMounted(() => {
    supported.value = isWebAuthnSupported();
});

const serverError = computed(
    () => page.props.errors.credential ?? page.props.errors.name,
);

async function addPasskey(): Promise<void> {
    notice.value = null;
    busy.value = true;

    try {
        const result = await createPasskey(
            passkeyRoutes.registrationOptions.url(),
        );

        if (!result.ok) {
            if (result.failure === 'password-confirmation-required') {
                router.post(
                    passkeyRoutes.store.url(),
                    {},
                    { preserveScroll: true },
                );

                return;
            }

            notice.value = ceremonyFailureMessage(result.failure);

            return;
        }

        router.post(
            passkeyRoutes.store.url(),
            { name: name.value, credential: result.credential },
            {
                preserveScroll: true,
                onSuccess: () => {
                    name.value = '';
                },
            },
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-text-muted">
            {{
                $t(
                    "A passkey signs you in with your device's screen lock or a security key instead of a password. It is tied to this site's address: a passkey created here does not sign you in on another of the operator's sites.",
                )
            }}
        </p>

        <ul
            v-if="props.passkeys.length > 0"
            class="divide-y rounded-lg border"
            data-test="passkey-list"
        >
            <li
                v-for="passkey in props.passkeys"
                :key="passkey.id"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="space-y-0.5">
                    <p class="text-sm font-medium">
                        {{ passkey.name }}
                        <span
                            v-if="passkey.authenticator"
                            class="font-normal text-text-muted"
                        >
                            · {{ passkey.authenticator }}
                        </span>
                    </p>
                    <p class="text-sm text-text-muted">
                        {{
                            $t('Added :time', {
                                time: formatDateTime(passkey.createdAt),
                            })
                        }}
                        <template v-if="passkey.lastUsedAt">
                            ·
                            {{
                                $t('Last used :time', {
                                    time: formatDateTime(passkey.lastUsedAt),
                                })
                            }}
                        </template>
                        <template v-else>· {{ $t('Never used') }}</template>
                    </p>
                </div>

                <Form
                    v-bind="passkeyRoutes.destroy.form(passkey.id)"
                    :options="{ preserveScroll: true }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :aria-label="
                            $t('Remove passkey :name', { name: passkey.name })
                        "
                    >
                        {{ $t('Remove') }}
                    </Button>
                </Form>
            </li>
        </ul>
        <p v-else class="text-sm text-text-muted" role="status">
            {{ $t('No passkeys yet.') }}
        </p>

        <form
            class="flex flex-col gap-2"
            data-test="add-passkey-form"
            @submit.prevent="addPasskey"
        >
            <Label for="passkey_name">{{
                $t('Name for the new passkey')
            }}</Label>
            <div class="flex flex-wrap items-start gap-2">
                <Input
                    id="passkey_name"
                    v-model="name"
                    name="name"
                    class="max-w-xs"
                    :placeholder="$t('e.g. Work laptop')"
                    autocomplete="off"
                    required
                    maxlength="255"
                    :aria-invalid="serverError ? true : undefined"
                    :aria-describedby="
                        serverError ? 'passkey_name_error' : undefined
                    "
                />
                <Button
                    type="submit"
                    :disabled="busy || !supported"
                    data-test="add-passkey-button"
                >
                    <Spinner v-if="busy" />
                    {{ $t('Add passkey') }}
                </Button>
            </div>
            <InputError id="passkey_name_error" :message="serverError" />
            <p
                v-if="notice"
                class="text-sm text-danger"
                role="alert"
                data-test="passkey-notice"
            >
                {{ notice }}
            </p>
            <p
                v-else-if="!supported"
                class="text-sm text-text-muted"
                role="status"
            >
                {{ $t('This browser does not support passkeys.') }}
            </p>
        </form>
    </div>
</template>
