<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import twoFactor from '@/routes/two-factor';

const props = defineProps<{
    enabled: boolean;
    confirming: boolean;
}>();

const qrCodeSvg = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);

async function loadQrCode(): Promise<void> {
    const response = await fetch(twoFactor.qrCode.url(), {
        headers: { Accept: 'application/json' },
    });
    const payload = (await response.json()) as { svg?: string };
    qrCodeSvg.value = payload.svg ?? null;
}

async function loadRecoveryCodes(): Promise<void> {
    const response = await fetch(twoFactor.recoveryCodes.url(), {
        headers: { Accept: 'application/json' },
    });
    recoveryCodes.value = (await response.json()) as string[];
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

onMounted(() => {
    if (props.enabled) {
        void loadRecoveryCodes();
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <Badge :variant="enabled ? 'default' : 'secondary'">
                {{ enabled ? 'Enabled' : 'Disabled' }}
            </Badge>
            <p class="text-sm text-muted-foreground">
                Use an authenticator app to require a code in addition to your
                password when logging in.
            </p>
        </div>

        <Form
            v-if="!enabled && !confirming"
            v-bind="twoFactor.enable.form()"
            :options="{ preserveScroll: true }"
        >
            <Button type="submit" data-test="enable-two-factor-button">
                Enable two-factor authentication
            </Button>
        </Form>

        <div v-else-if="confirming" class="space-y-4">
            <div
                v-if="qrCodeSvg"
                class="w-fit rounded-lg border p-4"
                v-html="qrCodeSvg"
            />
            <p v-else class="text-sm text-muted-foreground">Loading QR code…</p>

            <Form
                v-bind="twoFactor.confirm.form()"
                :options="{ preserveScroll: true }"
                @success="() => loadRecoveryCodes()"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-2"
            >
                <Label for="two_factor_code">Confirmation code</Label>
                <Input
                    id="two_factor_code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="max-w-40"
                    placeholder="Code"
                />
                <InputError :message="errors.code" />
                <Button
                    type="submit"
                    class="w-fit"
                    :disabled="processing"
                    data-test="confirm-two-factor-button"
                >
                    <Spinner v-if="processing" />
                    Confirm
                </Button>
            </Form>
        </div>

        <div v-else class="space-y-4">
            <div v-if="recoveryCodes.length" class="space-y-2">
                <p class="text-sm font-medium">Recovery codes</p>
                <p class="text-sm text-muted-foreground">
                    Store these somewhere safe. Each can be used once if you
                    lose access to your authenticator app.
                </p>
                <ul
                    class="grid grid-cols-2 gap-1 rounded-lg border bg-muted/50 p-4 font-mono text-sm"
                >
                    <li v-for="code in recoveryCodes" :key="code">
                        {{ code }}
                    </li>
                </ul>
                <Form
                    v-bind="twoFactor.regenerateRecoveryCodes.form()"
                    :options="{ preserveScroll: true }"
                    @success="() => loadRecoveryCodes()"
                >
                    <Button type="submit" variant="secondary" size="sm">
                        Regenerate recovery codes
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
                    Disable two-factor authentication
                </Button>
            </Form>
        </div>
    </div>
</template>
