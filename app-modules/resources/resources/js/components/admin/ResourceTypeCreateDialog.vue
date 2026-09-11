<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { store as storeType } from '@/routes/admin/resource-types';

/**
 * Creating a category asks for its identity only — brand, event, name,
 * slug, mode and token. Description, symbol, sort order and the default
 * place shape are the editor's job on the next screen (design §8.1).
 *
 * The slug follows the name until the operator edits it by hand, the way
 * every slug field in the console behaves.
 */
const props = defineProps<{
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    options: { mode: string[]; token: string[] };
}>();

const open = ref(false);
const slugTouched = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    eventId: '',
    name: '',
    slug: '',
    mode: 'unit',
    holdMinutes: 30,
    description: null as string | null,
    token: 'chart-1',
    symbol: null as string | null,
    sortOrder: 0,
    defaultShape: null as null | { type: string; w: number; h: number },
});

const eventsForBrand = computed(() =>
    props.events.filter((event) => event.brandId === form.brand_id),
);

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

watch(
    () => form.name,
    (name) => {
        if (!slugTouched.value) {
            form.slug = slugify(name);
        }
    },
);

function submit(): void {
    form.post(storeType().url, {
        onSuccess: () => {
            form.reset();
            slugTouched.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button>
                <Plus class="size-4" />
                New category
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Create category</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="category-brand">Brand</Label>
                    <select
                        id="category-brand"
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
                    <Label for="category-event">Event</Label>
                    <select
                        id="category-event"
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
                    <Label for="category-name">Name</Label>
                    <Input id="category-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="category-slug">Slug</Label>
                    <Input
                        id="category-slug"
                        v-model="form.slug"
                        @input="slugTouched = true"
                    />
                    <InputError :message="form.errors.slug" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="category-mode">Mode</Label>
                        <select
                            id="category-mode"
                            v-model="form.mode"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="value in props.options.mode"
                                :key="value"
                                :value="value"
                            >
                                {{ value }}
                            </option>
                        </select>
                        <InputError :message="form.errors.mode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="category-token">Colour token</Label>
                        <select
                            id="category-token"
                            v-model="form.token"
                            class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        >
                            <option
                                v-for="value in props.options.token"
                                :key="value"
                                :value="value"
                            >
                                {{ value }}
                            </option>
                        </select>
                        <InputError :message="form.errors.token" />
                    </div>
                </div>

                <p class="text-sm text-text-muted">
                    Seat categories are unit mode: one row per place. Symbol,
                    description and the default place size come next, on the
                    category's own screen.
                </p>

                <Button type="submit" :disabled="form.processing">
                    Create
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
