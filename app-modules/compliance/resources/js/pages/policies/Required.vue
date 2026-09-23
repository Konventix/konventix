<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { store as storeConsent } from '@/routes/consents';
import { show as showPolicy } from '@/routes/policies';

/**
 * Where both consent gates land — `require-consent` at a route, and
 * `require-contract-consent` in front of a contract-creating act (ADR-0066
 * §6.5, D-CPL-15): every policy this account still owes agreement to, its
 * full text inline, and one checkbox each.
 *
 * One checkbox per policy, never one for all of them — consent under
 * Art. 7(2) GDPR has to be specific, and a bundled "I agree to everything"
 * is exactly what the article rules out. Submit stays disabled until each is
 * ticked, so the button cannot record an agreement nobody made.
 *
 * `actLabel` is what the gate said the person was about to do, as a plain
 * string the owning module's route put there — "your registration for
 * Summer LAN 2026". Compliance never queries Registration to build it, and
 * it is absent for the login case, where there is no act to name.
 *
 * **"Not now" is a real way out**, and it increments nothing. The refusal
 * rate is derived on the dashboard from `consent_prompts_total` against
 * `consent_grants_total` (D-CPL-17) — a counter that only counted refusals
 * would invite treating a legal screen as a funnel. What it says instead is
 * what is actually true: the gated act cannot go ahead until the text is
 * agreed to, and everything else — the account, the tickets, the order
 * history — stays reachable. A modal was rejected outright: a legal text
 * behind a dismissible overlay is not an agreement.
 *
 * `v-html` on the rendered text: see `policies/Show.vue` for why that is
 * safe here.
 */
type PolicyVersionRow = {
    id: string;
    name: string;
    slug: string;
    version: string;
    effectiveAt: string | null;
    html: string;
};

const props = withDefaults(
    defineProps<{
        versions: PolicyVersionRow[];
        /** What the gate said this is standing in front of, or null. */
        actLabel?: string | null;
        /** Where "Not now" leaves to — the brand's own front page. */
        notNowUrl?: string;
    }>(),
    { actLabel: null, notNowUrl: '/' },
);

const accepted = ref<Record<string, boolean>>(
    Object.fromEntries(props.versions.map((version) => [version.id, false])),
);

const form = useForm({
    versionIds: [] as string[],
    captureContext: 'consent_gate',
});

function allAccepted(): boolean {
    return props.versions.every((version) => accepted.value[version.id]);
}

function submit(): void {
    form.versionIds = props.versions.map((version) => version.id);
    form.post(storeConsent().url);
}
</script>

<template>
    <Head :title="$t('Before you continue')" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <header class="flex flex-col gap-2">
            <h1 class="text-2xl font-semibold">
                <template v-if="props.actLabel">{{
                    $t('Before you continue with :act', {
                        act: props.actLabel,
                    })
                }}</template>
                <template v-else>{{ $t('Before you continue') }}</template>
            </h1>
            <p class="text-sm text-text-muted">
                {{
                    $t(
                        'These documents changed, or you have not agreed to them yet. Please read and confirm each one.',
                    )
                }}
            </p>
        </header>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <section
                v-for="version in props.versions"
                :key="version.id"
                class="flex flex-col gap-3 rounded-lg border border-border p-4"
            >
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-medium">{{ version.name }}</h2>
                    <Link
                        :href="showPolicy(version.slug).url"
                        class="text-xs text-text-muted underline hover:text-text"
                        target="_blank"
                    >
                        {{ $t('Open in a new tab') }}
                    </Link>
                </div>

                <!-- eslint-disable-next-line vue/no-v-html -->
                <article
                    class="policy-prose max-h-72 overflow-y-auto rounded-md bg-surface-muted p-3 text-sm"
                    v-html="version.html"
                />

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="accepted[version.id]"
                        :aria-label="
                            $t('I agree to :name', { name: version.name })
                        "
                        @update:model-value="
                            (value: boolean | 'indeterminate') => {
                                accepted[version.id] = value === true;
                            }
                        "
                    />
                    {{
                        $t('I agree to :name (:version)', {
                            name: version.name,
                            version: version.version,
                        })
                    }}
                </label>
            </section>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="!allAccepted() || form.processing"
                >
                    {{ $t('Agree and continue') }}
                </Button>
                <span
                    v-if="!allAccepted()"
                    role="status"
                    class="text-sm text-text-muted"
                >
                    {{ $t('Confirm every document to continue.') }}
                </span>
            </div>
        </form>

        <section class="flex flex-col gap-1 border-t border-border pt-4">
            <Link
                :href="props.notNowUrl"
                class="text-sm underline underline-offset-4"
                data-test="consent-not-now"
            >
                {{ $t('Not now') }}
            </Link>
            <p class="text-sm text-text-muted">
                <template v-if="props.actLabel">{{
                    $t(
                        'You can leave this here — but :act cannot go ahead until you have agreed to the documents above.',
                        { act: props.actLabel },
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'You can leave this here — but nothing that needs your agreement can go ahead until you have agreed to the documents above.',
                    )
                }}</template>
                {{
                    $t(
                        'Your account, your tickets and your order history stay reachable either way.',
                    )
                }}
            </p>
        </section>
    </div>
</template>

<style scoped>
/* Same few element rules as the standalone policy page. */
.policy-prose :deep(h2),
.policy-prose :deep(h3) {
    font-weight: 600;
    margin-top: 0.75rem;
}

.policy-prose :deep(ul),
.policy-prose :deep(ol) {
    padding-left: 1.5rem;
    list-style: revert;
}

.policy-prose :deep(a) {
    color: var(--accent);
    text-decoration: underline;
}
</style>
