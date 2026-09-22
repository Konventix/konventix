<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import { store as storeDiscipline } from '@/routes/admin/disciplines';

/**
 * Creating a discipline asks for what it *is* — brand, kind, name, slug and
 * the contest defaults every competition of it inherits (ADR-0024,
 * ADR-0062). The GameOps game id belongs to the editor on the next screen:
 * there is no catalogue picker yet, because GameOps publishes no catalogue
 * event to build one from.
 *
 * The slug follows the name until the operator edits it by hand, the way
 * every slug field in the console behaves.
 */
const props = defineProps<{
    brands: { id: string; name: string }[];
    options: { kind: string[] };
}>();

const open = ref(false);
const slugTouched = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    kind: 'video_game',
    name: '',
    slug: '',
    teamSize: 1,
    avgMatchMinutes: 30,
    allowDraws: false,
    gameId: null as string | null,
});

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
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
    form.post(storeDiscipline().url, {
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
                New discipline
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Create discipline</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="discipline-brand">Brand</Label>
                    <select
                        id="discipline-brand"
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
                    <Label for="discipline-kind">Kind</Label>
                    <select
                        id="discipline-kind"
                        v-model="form.kind"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    >
                        <option
                            v-for="value in props.options.kind"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </option>
                    </select>
                    <p class="text-xs text-text-muted">
                        A video game is one kind of discipline, not the only
                        one — a table-football table and a quiz are others.
                    </p>
                    <InputError :message="form.errors.kind" />
                </div>

                <div class="grid gap-2">
                    <Label for="discipline-name">Name</Label>
                    <Input id="discipline-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="discipline-slug">Slug</Label>
                    <Input
                        id="discipline-slug"
                        v-model="form.slug"
                        @input="slugTouched = true"
                    />
                    <InputError :message="form.errors.slug" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="discipline-team-size">Team size</Label>
                        <Input
                            id="discipline-team-size"
                            v-model.number="form.teamSize"
                            type="number"
                            min="1"
                        />
                        <p class="text-xs text-text-muted">
                            1 is a solo contest — a team of one.
                        </p>
                        <InputError :message="form.errors.teamSize" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="discipline-minutes">
                            Average match minutes
                        </Label>
                        <Input
                            id="discipline-minutes"
                            v-model.number="form.avgMatchMinutes"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.avgMatchMinutes" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.allowDraws"
                        type="checkbox"
                        class="size-4 rounded border-border"
                    />
                    Draws are possible
                </label>
                <InputError :message="form.errors.allowDraws" />

                <Button type="submit" :disabled="form.processing">
                    Create
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
