<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import RecoveryCodesDialog from '@/components/RecoveryCodesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import twoFactor from '@/routes/two-factor';
import {
    becameEnabled,
    noCodesRevealed,
    revealCodes,
} from './recoveryCodesReveal';
import type { RecoveryCodesReveal } from './recoveryCodesReveal';

const props = defineProps<{
    enabled: boolean;
    confirming: boolean;
}>();

const qrCodeSvg = ref<string | null>(null);

/**
 * The recovery codes are never fetched on mount and never listed on the page:
 * they are on screen exactly once, right after the enrolment is confirmed or
 * a regeneration, in a dialog that closes only past the "I saved them"
 * checkbox (`recoveryCodesReveal.ts`).
 *
 * The checkbox is a nudge; the one-shot is enforced on the server since
 * D-IDP-18 (round 4, Task 26). Each of the two moments below mints a grant
 * that the very next GET of `two-factor.recovery-codes` spends, and any
 * further GET — a reload, a second tab — is a 404. That is why this component
 * fires exactly one fetch per moment and never one on mount: an extra call
 * would not re-show the codes, it would burn the grant the dialog needs.
 */
const reveal = ref<RecoveryCodesReveal>(noCodesRevealed);

async function loadQrCode(): Promise<void> {
    const response = await fetch(twoFactor.qrCode.url(), {
        headers: { Accept: 'application/json' },
    });
    const payload = (await response.json()) as { svg?: string };
    qrCodeSvg.value = payload.svg ?? null;
}

async function revealFreshRecoveryCodes(): Promise<void> {
    const response = await fetch(twoFactor.recoveryCodes.url(), {
        headers: { Accept: 'application/json' },
    });
    reveal.value = revealCodes((await response.json()) as string[]);
}

watch(
    () => props.confirming,
    (confirming) => {
        if (confirming) {
            void loadQrCode();
        }
    },
    { immediate: true },
);

// The confirmation moment is read off the props rather than off the confirm
// form's `@success`: Fortify redirects back, the page keeps its state (a
// non-GET `<Form>` visit preserves it), and the props flip from confirming
// to enabled while this component stays mounted — whereas the confirm form
// itself is unmounted by that flip, and Vue drops an emit from an unmounted
// component. Not immediate, so a page that loads already enabled shows
// nothing.
watch(
    () => props.enabled,
    (isEnabled, wasEnabled) => {
        if (becameEnabled(wasEnabled, isEnabled)) {
            void revealFreshRecoveryCodes();
        }
    },
);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <Badge :variant="enabled ? 'default' : 'secondary'">
                {{ enabled ? $t('Enabled') : $t('Disabled') }}
            </Badge>
            <p class="text-sm text-text-muted">
                {{
                    $t(
                        'Use an authenticator app to require a code in addition to your password when logging in.',
                    )
                }}
            </p>
        </div>

        <Form
            v-if="!enabled && !confirming"
            v-bind="twoFactor.enable.form()"
            :options="{ preserveScroll: true }"
        >
            <Button type="submit" data-test="enable-two-factor-button">
                {{ $t('Enable two-factor authentication') }}
            </Button>
        </Form>

        <div v-else-if="confirming" class="space-y-4">
            <div
                v-if="qrCodeSvg"
                class="w-fit rounded-lg border border-border p-4"
                v-html="qrCodeSvg"
            />
            <p v-else class="text-sm text-text-muted">
                {{ $t('Loading QR code…') }}
            </p>

            <Form
                v-bind="twoFactor.confirm.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-2"
            >
                <Label for="two_factor_code">{{
                    $t('Confirmation code')
                }}</Label>
                <Input
                    id="two_factor_code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="max-w-40"
                    :placeholder="$t('Code')"
                />
                <InputError :message="errors.code" />
                <Button
                    type="submit"
                    class="w-fit"
                    :disabled="processing"
                    data-test="confirm-two-factor-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Confirm') }}
                </Button>
            </Form>
        </div>

        <div v-else class="space-y-4">
            <div class="space-y-2">
                <p class="text-sm font-medium">{{ $t('Recovery codes') }}</p>
                <p class="text-sm text-text-muted">
                    {{
                        $t(
                            'Your recovery codes were shown once when you enabled two-factor authentication. If you no longer have them, generate a new set — the old codes stop working.',
                        )
                    }}
                </p>
                <Form
                    v-bind="twoFactor.regenerateRecoveryCodes.form()"
                    :options="{ preserveScroll: true }"
                    @success="() => revealFreshRecoveryCodes()"
                >
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        data-test="regenerate-recovery-codes-button"
                    >
                        {{ $t('Regenerate recovery codes') }}
                    </Button>
                </Form>
            </div>

            <Form
                v-bind="twoFactor.disable.form()"
                :options="{ preserveScroll: true }"
            >
                <Button
                    type="submit"
                    variant="destructive"
                    data-test="disable-two-factor-button"
                >
                    {{ $t('Disable two-factor authentication') }}
                </Button>
            </Form>
        </div>

        <RecoveryCodesDialog v-model="reveal" />
    </div>
</template>
