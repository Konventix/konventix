<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    destroy as clearStripeSettings,
    update as saveStripeSettings,
} from '@/routes/admin/settlement-accounts/stripe-settings';

/**
 * The Stripe account this whole instance settles into (ADR-0058 point 2):
 * one secret key and one webhook signing secret, either entered here — then
 * stored encrypted and winning over the environment — or set as
 * STRIPE_SECRET / STRIPE_WEBHOOK_SECRET. Not per brand: a brand's Stripe row
 * below is only the flag that says the brand sells through this account.
 *
 * Whether, never what: the props say which key is installed by its mode and
 * last four characters, so this card can never render a key it was not
 * given — because it was never given one.
 */
type Props = { setup: Konventix.Commerce.Data.StripeSetupData };

const props = defineProps<Props>();

const form = useForm({
    secretKey: '',
    webhookSecret: '',
    publishableKey: '',
});

useUnsavedChanges(() => form.isDirty);

/**
 * What was last copied out of the setup block, announced in a
 * `role="status"` region rather than only flashed on the button (#161,
 * ADR-0055). `navigator.clipboard` is unavailable over plain HTTP and in
 * older browsers, so the values stay in readable, selectable elements and
 * copying is only the convenience on top of them — a failed copy says
 * nothing rather than claiming success.
 */
const copied = ref<string | null>(null);

async function copyToClipboard(what: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = what;
    } catch {
        copied.value = null;
    }
}

/**
 * Which half of the pair is missing, in the operator's words. Never reached
 * while `configured` is true.
 */
const missingHalf = computed(() => {
    if (!props.setup.secretKeySet && !props.setup.webhookSecretSet) {
        return trans(
            'neither the Secret Key nor the Webhook Signing Secret is set',
        );
    }

    return props.setup.secretKeySet
        ? trans('the Webhook Signing Secret is missing')
        : trans('the Secret Key is missing');
});

const sourceLabels: Record<string, string> = {
    database: trans('entered in this console'),
    environment: trans('from the environment'),
    none: trans('not configured'),
};

function submit(): void {
    form.transform((data) => ({
        secretKey: data.secretKey,
        webhookSecret: data.webhookSecret,
        publishableKey: data.publishableKey === '' ? null : data.publishableKey,
    })).put(saveStripeSettings().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function clear(): void {
    if (
        confirm(
            trans(
                'Clear the Stripe keys entered here? Payments then run on STRIPE_SECRET from the environment, or stop until a new key is entered.',
            ),
        )
    ) {
        router.delete(clearStripeSettings().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Card class="border-border bg-surface">
        <CardHeader>
            <CardTitle>{{ $t('Stripe account') }}</CardTitle>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <p class="max-w-2xl text-sm text-text-muted">
                {{
                    $t(
                        'Konventix settles into one Stripe account — this instance’s own. Every brand below that sells through Stripe is settled on this one account and told apart by the metadata each payment carries. Enter the account’s Secret Key and Webhook Signing Secret here: they are stored encrypted and take precedence over the environment variables',
                    )
                }}
                <code>{{ 'STRIPE_SECRET' }}</code> /
                <code>{{ 'STRIPE_WEBHOOK_SECRET' }}</code
                >.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Badge
                    :variant="
                        props.setup.secretKeySet ? 'default' : 'destructive'
                    "
                >
                    {{
                        props.setup.secretKeySet
                            ? $t('Secret key set')
                            : $t('No secret key')
                    }}
                </Badge>
                <Badge
                    :variant="
                        props.setup.webhookSecretSet ? 'default' : 'destructive'
                    "
                >
                    {{
                        props.setup.webhookSecretSet
                            ? $t('Signing secret set')
                            : $t('No signing secret')
                    }}
                </Badge>
                <Badge v-if="props.setup.mode" variant="secondary">
                    {{
                        props.setup.mode === 'live'
                            ? $t('Live mode')
                            : $t('Test mode')
                    }}
                </Badge>
                <span class="text-xs text-text-muted">
                    {{ sourceLabels[props.setup.source] ?? props.setup.source }}
                    <template v-if="props.setup.lastFour">
                        — {{ $t('key ending') }}
                        <code>{{ props.setup.lastFour }}</code>
                    </template>
                </span>
                <span
                    v-if="props.setup.publishableKeySet"
                    class="text-xs text-text-muted"
                >
                    {{ $t('publishable key set (unused by hosted Checkout)') }}
                </span>
            </div>

            <!--
                ADR-0039 point 2: the signed webhook is the only source of
                settlement truth, so half a pair is not a working Stripe
                account — it is one that takes money and never settles it.
                The card says which half is missing rather than leaving the
                operator to compare two badges.
            -->
            <p
                v-if="!props.setup.configured"
                role="status"
                class="rounded-md border border-border p-3 text-sm text-text-muted"
            >
                {{ $t('Stripe is not offered at checkout yet:') }}
                <strong>{{ missingHalf }}</strong
                >.
                {{
                    $t(
                        'Both are needed — a checkout started without a signing secret would take the payer’s money and never settle the order, because the webhook could not be verified.',
                    )
                }}
            </p>

            <!--
                ADR-0053: a live key on anything but production is one
                checkout away from moving real money against rehearsal data.
            -->
            <p
                v-if="props.setup.liveKeyOffProduction"
                role="alert"
                class="rounded-md border border-danger p-3 text-sm font-medium text-danger"
            >
                <code>{{ 'sk_live_' }}</code
                >:
                {{
                    $t(
                        'This is not a production instance, and the key installed here is a live key. Any checkout started here moves real money. Replace it with the sandbox key unless that is deliberate.',
                    )
                }}
            </p>

            <form class="flex max-w-md flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label for="stripe_secret_key">{{
                        $t('Secret Key')
                    }}</Label>
                    <Input
                        id="stripe_secret_key"
                        v-model="form.secretKey"
                        type="password"
                        autocomplete="off"
                        :placeholder="'sk_test_…'"
                        :aria-invalid="form.errors.secretKey ? true : undefined"
                        :aria-describedby="
                            form.errors.secretKey
                                ? 'stripe_secret_key-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="stripe_secret_key-error"
                        :message="form.errors.secretKey"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="stripe_webhook_secret">{{
                        $t('Webhook Signing Secret')
                    }}</Label>
                    <Input
                        id="stripe_webhook_secret"
                        v-model="form.webhookSecret"
                        type="password"
                        autocomplete="off"
                        :placeholder="'whsec_…'"
                        :aria-invalid="
                            form.errors.webhookSecret ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.webhookSecret
                                ? 'stripe_webhook_secret-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="stripe_webhook_secret-error"
                        :message="form.errors.webhookSecret"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="stripe_publishable_key">{{
                        $t('Publishable Key (optional)')
                    }}</Label>
                    <Input
                        id="stripe_publishable_key"
                        v-model="form.publishableKey"
                        type="text"
                        autocomplete="off"
                        :placeholder="'pk_test_…'"
                        :aria-invalid="
                            form.errors.publishableKey ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.publishableKey
                                ? 'stripe_publishable_key-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="stripe_publishable_key-error"
                        :message="form.errors.publishableKey"
                    />
                    <p class="text-xs text-text-muted">
                        {{
                            $t(
                                'Never needed: no card field renders on a Konventix page. Stored for completeness only.',
                            )
                        }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{
                            props.setup.source === 'database'
                                ? $t('Replace keys')
                                : $t('Save keys')
                        }}
                    </Button>
                    <Button
                        v-if="props.setup.source === 'database'"
                        type="button"
                        variant="outline"
                        @click="clear"
                    >
                        {{ $t('Clear keys') }}
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        {{ $t('Unsaved changes') }}
                    </span>
                </div>
            </form>

            <!--
                ADR-0058 point 5: one ordinary endpoint per environment. An
                operator meets this card before they meet the docs, so what
                Stripe has to be told is answered here.
            -->
            <section
                aria-labelledby="stripe_setup_heading"
                class="flex max-w-2xl flex-col gap-3 rounded-md border border-border bg-surface-muted p-4"
            >
                <h3
                    id="stripe_setup_heading"
                    class="text-sm font-semibold text-text"
                >
                    {{ $t('Setting the account up at Stripe') }}
                </h3>

                <p class="text-xs text-text-muted">
                    {{ props.setup.secretGroupHint }}
                    {{ $t('See “Stripe credentials per environment” in') }}
                    <code>{{ 'docs/helm-chart-requirements.md' }}</code
                    >.
                </p>

                <div class="flex flex-col gap-2">
                    <Label for="stripe_webhook_url">{{
                        $t('Webhook endpoint for this instance')
                    }}</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="stripe_webhook_url"
                            :model-value="props.setup.webhookUrl"
                            readonly
                            class="font-mono text-xs"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="
                                copyToClipboard(
                                    $t('Webhook endpoint'),
                                    props.setup.webhookUrl,
                                )
                            "
                        >
                            <span aria-hidden="true">{{ $t('Copy') }}</span>
                            <span class="sr-only">{{
                                $t('Copy the webhook endpoint URL')
                            }}</span>
                        </Button>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <p
                        id="stripe_events_heading"
                        class="text-sm font-medium text-text"
                    >
                        {{ $t('Events this endpoint must receive') }}
                    </p>
                    <ul
                        aria-labelledby="stripe_events_heading"
                        class="flex flex-col font-mono text-xs text-text-muted"
                    >
                        <li v-for="event in props.setup.events" :key="event">
                            {{ event }}
                        </li>
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="self-start"
                        @click="
                            copyToClipboard(
                                $t('Event list'),
                                props.setup.events.join(','),
                            )
                        "
                    >
                        <span aria-hidden="true">{{ $t('Copy all') }}</span>
                        <span class="sr-only">{{
                            $t(
                                'Copy all event names, comma-separated for the Stripe dashboard or the CLI',
                            )
                        }}</span>
                    </Button>
                </div>

                <p role="status" class="text-xs text-success">
                    <template v-if="copied !== null">{{
                        $t(':what copied to the clipboard.', { what: copied })
                    }}</template>
                </p>

                <p class="text-xs text-text-muted">
                    {{
                        $t(
                            'Create that endpoint in the Stripe dashboard under Developers → Webhooks as an ordinary endpoint on this account — leave “Listen to events on connected accounts” off, nothing here happens on a connected account. The endpoint’s own signing secret, revealed on its detail page, is the Webhook Signing Secret above; every environment has its own endpoint and therefore its own secret.',
                        )
                    }}
                </p>

                <p class="text-xs text-text-muted">
                    {{
                        $t(
                            'The Secret Key is this account’s standard key under Developers → API keys: the sandbox key for development and staging, the live key for production. Local development needs no endpoint at all; this script tunnels the same events and prints the signing secret to use instead:',
                        )
                    }}
                    <code>{{ 'contrib/stripe-listen.sh' }}</code>
                </p>
            </section>
        </CardContent>
    </Card>
</template>
