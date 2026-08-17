<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PublicTopbar from '@/components/PublicTopbar.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { show as showPolicy } from '@/routes/policies';
import { store as storeConsent } from '@/routes/consents';

/**
 * Where the `require-consent` gate lands: every policy this account still
 * owes agreement to, its full text inline, and one checkbox each.
 *
 * One checkbox per policy, never one for all of them — consent under
 * Art. 7(2) GDPR has to be specific, and a bundled "I agree to everything"
 * is exactly what the article rules out. Submit stays disabled until each is
 * ticked, so the button cannot record an agreement nobody made.
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

const props = defineProps<{ versions: PolicyVersionRow[] }>();

defineOptions({ layout: null });

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
    <Head title="Before you continue" />

    <div class="flex min-h-screen flex-col bg-surface text-text">
        <PublicTopbar :name="$page.props.name" />

        <main
            class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-6 lg:p-8"
        >
            <header class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold">Before you continue</h1>
                <p class="text-sm text-text-muted">
                    These documents changed, or you have not agreed to them
                    yet. Please read and confirm each one.
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
                            Open in a new tab
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
                            :aria-label="`I agree to ${version.name}`"
                            @update:model-value="
                                (value: boolean | 'indeterminate') => {
                                    accepted[version.id] = value === true;
                                }
                            "
                        />
                        I agree to {{ version.name }} ({{ version.version }})
                    </label>
                </section>

                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        :disabled="!allAccepted() || form.processing"
                    >
                        Agree and continue
                    </Button>
                    <span
                        v-if="!allAccepted()"
                        role="status"
                        class="text-sm text-text-muted"
                    >
                        Confirm every document to continue.
                    </span>
                </div>
            </form>
        </main>
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
