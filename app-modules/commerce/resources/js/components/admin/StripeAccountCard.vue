<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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

const sourceLabels: Record<string, string> = {
    database: 'entered in this console',
    environment: 'from the environment',
    none: 'not configured',
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
            'Clear the Stripe keys entered here? Payments then run on STRIPE_SECRET from the environment, or stop until a new key is entered.',
        )
    ) {
        router.delete(clearStripeSettings().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Card class="border-border bg-surface">
        <CardHeader>
            <CardTitle>Stripe account</CardTitle>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <p class="max-w-2xl text-sm text-text-muted">
                Konventix settles into one Stripe account — this instance's own.
                Enter its Secret Key and Webhook Signing Secret here and they
                are stored encrypted and used instead of
                <code>STRIPE_SECRET</code> / <code>STRIPE_WEBHOOK_SECRET</code>
                from the environment. Every brand below that sells through
                Stripe is settled on this one account and told apart by the
                metadata each payment carries.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Badge
                    :variant="
                        props.setup.configured ? 'default' : 'destructive'
                    "
                >
                    {{
                        props.setup.configured
                            ? 'Secret key set'
                            : 'No secret key'
                    }}
                </Badge>
                <Badge
                    :variant="
                        props.setup.webhookSecretSet ? 'default' : 'destructive'
                    "
                >
                    {{
                        props.setup.webhookSecretSet
                            ? 'Signing secret set'
                            : 'No signing secret'
                    }}
                </Badge>
                <Badge v-if="props.setup.mode" variant="secondary">
                    {{
                        props.setup.mode === 'live' ? 'Live mode' : 'Test mode'
                    }}
                </Badge>
                <span class="text-xs text-text-muted">
                    {{ sourceLabels[props.setup.source] ?? props.setup.source }}
                    <template v-if="props.setup.lastFour">
                        — key ending
                        <code>{{ props.setup.lastFour }}</code>
                    </template>
                </span>
                <span
                    v-if="props.setup.publishableKeySet"
                    class="text-xs text-text-muted"
                >
                    publishable key set (unused by hosted Checkout)
                </span>
            </div>

            <!--
                ADR-0053: a live key on anything but production is one
                checkout away from moving real money against rehearsal data.
            -->
            <p
                v-if="props.setup.liveKeyOffProduction"
                role="alert"
                class="rounded-md border border-danger p-3 text-sm font-medium text-danger"
            >
                This is not a production instance, and the key installed here is
                a live key (<code>sk_live_</code>). Any checkout started here
                moves real money. Replace it with the sandbox key unless that is
                deliberate.
            </p>

            <form class="flex max-w-md flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label for="stripe_secret_key">Secret Key</Label>
                    <Input
                        id="stripe_secret_key"
                        v-model="form.secretKey"
                        type="password"
                        autocomplete="off"
                        placeholder="sk_test_…"
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
                    <Label for="stripe_webhook_secret"
                        >Webhook Signing Secret</Label
                    >
                    <Input
                        id="stripe_webhook_secret"
                        v-model="form.webhookSecret"
                        type="password"
                        autocomplete="off"
                        placeholder="whsec_…"
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
                    <Label for="stripe_publishable_key"
                        >Publishable Key (optional)</Label
                    >
                    <Input
                        id="stripe_publishable_key"
                        v-model="form.publishableKey"
                        type="text"
                        autocomplete="off"
                        placeholder="pk_test_…"
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
                        Never needed: no card field renders on a Konventix page.
                        Stored for completeness only.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{
                            props.setup.source === 'database'
                                ? 'Replace keys'
                                : 'Save keys'
                        }}
                    </Button>
                    <Button
                        v-if="props.setup.source === 'database'"
                        type="button"
                        variant="outline"
                        @click="clear"
                    >
                        Clear keys
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
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
                    Setting the account up at Stripe
                </h3>

                <p class="text-xs text-text-muted">
                    {{ props.setup.secretGroupHint }}
                    See "Stripe credentials per environment" in
                    <code>docs/helm-chart-requirements.md</code>.
                </p>

                <div class="flex flex-col gap-2">
                    <Label for="stripe_webhook_url"
                        >Webhook endpoint for this instance</Label
                    >
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
                                    'Webhook endpoint',
                                    props.setup.webhookUrl,
                                )
                            "
                        >
                            Copy<span class="sr-only">
                                the webhook endpoint URL</span
                            >
                        </Button>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <p
                        id="stripe_events_heading"
                        class="text-sm font-medium text-text"
                    >
                        Events this endpoint must receive
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
                                'Event list',
                                props.setup.events.join(','),
                            )
                        "
                    >
                        Copy all<span class="sr-only">
                            event names, comma-separated for the Stripe
                            dashboard or the CLI</span
                        >
                    </Button>
                </div>

                <p role="status" class="text-xs text-success">
                    <template v-if="copied !== null"
                        >{{ copied }} copied to the clipboard.</template
                    >
                </p>

                <p class="text-xs text-text-muted">
                    Create that endpoint in the Stripe dashboard under
                    Developers → Webhooks as an ordinary endpoint on this
                    account — leave "Listen to events on connected accounts"
                    off, nothing here happens on a connected account. The
                    endpoint's own signing secret, revealed on its detail page,
                    is the Webhook Signing Secret above; every environment has
                    its own endpoint and therefore its own secret.
                </p>

                <p class="text-xs text-text-muted">
                    The Secret Key is this account's standard key under
                    Developers → API keys: the sandbox key for development and
                    staging, the live key for production. Local development
                    needs no endpoint at all —
                    <code>contrib/stripe-listen.sh</code> tunnels the same
                    events and prints the signing secret to use instead.
                </p>
            </section>
        </CardContent>
    </Card>
</template>
