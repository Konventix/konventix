<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { upload as uploadPlan } from '@/routes/admin/plans';

/**
 * A full `konventix.plan/v1` document becomes a new plan (design §6.4).
 * The server runs it through `SavePlan`, so a structural error comes back
 * as an ordinary field error on `document.elements.<index>.<field>`; the
 * dialog shows the first of those under the file input, because a keyed
 * list of every element error is the editor's job, not a dialog's.
 */
const props = defineProps<{
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
}>();

const open = ref(false);

const form = useForm<{
    brand_id: string;
    eventId: string;
    name: string;
    file: File | null;
}>({
    brand_id: props.brands[0]?.id ?? '',
    eventId: '',
    name: '',
    file: null,
});

const eventsForBrand = computed(() =>
    props.events.filter((event) => event.brandId === form.brand_id),
);

const documentError = computed(() => {
    const key = Object.keys(form.errors).find((name) =>
        name.startsWith('document.'),
    );

    return key
        ? `${key}: ${form.errors[key as keyof typeof form.errors]}`
        : undefined;
});

function submit(): void {
    form.post(uploadPlan().url, {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline">
                <Upload class="size-4" />
                Import
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import a plan document</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="import-brand">Brand</Label>
                    <select
                        id="import-brand"
                        v-model="form.brand_id"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    >
                        <option
                            v-for="brand in props.brands"
                            :key="brand.id"
                            :value="brand.id"
                        >
                            {{ brand.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.brand_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="import-event">Event</Label>
                    <select
                        id="import-event"
                        v-model="form.eventId"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    >
                        <option value="" disabled>Choose an event</option>
                        <option
                            v-for="event in eventsForBrand"
                            :key="event.id"
                            :value="event.id"
                        >
                            {{ event.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.eventId" />
                </div>
                <div class="grid gap-2">
                    <Label for="import-name">Plan name</Label>
                    <Input id="import-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="import-file">Document (JSON)</Label>
                    <input
                        id="import-file"
                        type="file"
                        accept="application/json,.json"
                        class="text-sm"
                        @change="
                            form.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p class="text-xs text-text-muted">
                        A <code>konventix.plan/v1</code> export. Element ids the
                        export carries are kept; ids that already exist under
                        another plan are refused.
                    </p>
                    <InputError :message="form.errors.file ?? documentError" />
                </div>
                <Button type="submit" :disabled="form.processing || !form.file">
                    Import
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
