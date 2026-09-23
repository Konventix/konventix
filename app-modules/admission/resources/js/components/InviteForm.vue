<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Link2, Mail } from '@lucide/vue';
import { useId } from 'vue';
import InviteController from '@/actions/Konventix/Admission/Http/Controllers/InviteController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

/**
 * The invite form under a ticket manager row (#292 §4.5): an address binds
 * the link to one recipient and mails it; no address mints a bearer link
 * the manager copies and passes on however they like. Two submit buttons,
 * one form — the address input decides which one makes sense, and the
 * server decides the rest. A typed address is state worth guarding
 * (ADR-0038), so the unsaved-changes guard watches the form.
 */
const props = defineProps<{ admissionId: string }>();
const emit = defineEmits<{ done: [] }>();

const id = useId();
const form = useForm<{ email: string }>({ email: '' });

useUnsavedChanges(() => form.isDirty);

function submit(withAddress: boolean): void {
    const data = withAddress ? { email: form.email } : { email: null };

    form.transform(() => data).post(
        InviteController.store.url({ admission: props.admissionId }),
        {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('done');
            },
        },
    );
}
</script>

<template>
    <form
        class="flex flex-col gap-3 rounded-md border border-border bg-surface-muted p-3"
        :aria-label="$t('Invite somebody to hold this ticket')"
        @submit.prevent="submit(true)"
    >
        <div class="grid gap-1">
            <Label :for="`${id}-email`">{{ $t('Their email address') }}</Label>
            <Input
                :id="`${id}-email`"
                v-model="form.email"
                type="email"
                autocomplete="off"
                placeholder="name@example.com"
                :aria-invalid="form.errors.email ? 'true' : undefined"
                :aria-describedby="
                    form.errors.email ? `${id}-error` : undefined
                "
            />
            <div :id="`${id}-error`">
                <InputError :message="form.errors.email" />
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="submit"
                size="sm"
                :disabled="form.processing || form.email.trim() === ''"
            >
                <Mail class="size-4" />
                {{ $t('Send invitation') }}
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="form.processing"
                @click="submit(false)"
            >
                <Link2 class="size-4" />
                {{ $t('Create a link to copy') }}
            </Button>
            <span class="text-xs text-text-muted">
                {{
                    $t(
                        'A mailed invitation works only for that address. A copied link works for whoever opens it first.',
                    )
                }}
            </span>
        </div>
    </form>
</template>
