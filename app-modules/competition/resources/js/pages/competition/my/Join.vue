<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Join by invite code (design §5.2, §10) — the only self-service way onto a
 * roster.
 *
 * The field is prefilled from `?code=`, so a captain can paste one link into
 * Discord instead of a code eight people have to retype. Normalising happens
 * on the server as well; what happens here is only so the field shows the
 * same characters the server will look up — spaces and dashes out, upper
 * case, eight characters.
 *
 * Participant copy is German (ADR-0001).
 *
 * a11y: the error is linked to the field through `aria-describedby` and
 * `aria-invalid`, per `.ai/rules/js.md` — the public pages target WCAG 2.1 AA
 * whatever the operator's own exemption says (ADR-0055).
 */
const props = defineProps<{ code: string }>();

const form = useForm({ code: props.code ?? '' });

const nonAlphanumeric = /[^A-Za-z0-9]/g;

function normalize(value: string): string {
    return value.replace(nonAlphanumeric, '').toUpperCase().slice(0, 8);
}

function submit(): void {
    form.code = normalize(form.code);
    form.post('/my/tournaments/join', { preserveScroll: true });
}
</script>

<template>
    <Head title="Team beitreten" />

    <div class="mx-auto w-full max-w-md px-4 py-6">
        <Heading
            as="h1"
            title="Team beitreten"
            description="Gib den Einladungscode ein, den dein Captain geteilt hat."
        />

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex flex-col gap-2">
                <Label for="invite-code">Einladungscode</Label>
                <Input
                    id="invite-code"
                    v-model="form.code"
                    name="code"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="32"
                    class="font-mono tracking-[0.3em] uppercase"
                    :aria-invalid="form.errors.code ? 'true' : undefined"
                    :aria-describedby="
                        form.errors.code
                            ? 'invite-code-error'
                            : 'invite-code-hint'
                    "
                    @blur="form.code = normalize(form.code)"
                />
                <p id="invite-code-hint" class="text-sm text-text-muted">
                    Acht Zeichen. 0, O, 1 und I kommen in Codes nie vor.
                </p>
                <InputError
                    id="invite-code-error"
                    :message="form.errors.code"
                />
            </div>

            <Button
                type="submit"
                :disabled="form.processing || form.code.length === 0"
            >
                Beitreten
            </Button>
        </form>
    </div>
</template>
