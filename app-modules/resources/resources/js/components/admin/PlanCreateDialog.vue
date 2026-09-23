<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
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
import { store as storePlan } from '@/routes/admin/plans';

/**
 * The plan header (design §8.2): event from the window read model, name,
 * floor label, size in millimetres. Unit display and snap step take the
 * §4.4 defaults; the editor changes them.
 */
const props = defineProps<{
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
}>();

const open = ref(false);

const form = useForm({
    brand_id: props.brands[0]?.id ?? '',
    eventId: '',
    name: '',
    floorLabel: '' as string | null,
    widthMm: 40000,
    heightMm: 25000,
    unitDisplay: 'cm',
    snapStep: 100,
});

const eventsForBrand = computed(() =>
    props.events.filter((event) => event.brandId === form.brand_id),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        floorLabel: data.floorLabel === '' ? null : data.floorLabel,
    })).post(storePlan().url, {
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
            <Button>
                <Plus class="size-4" />
                {{ $t('New plan') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Create plan') }}</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="plan-brand">{{ $t('Brand') }}</Label>
                    <select
                        id="plan-brand"
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
                    <Label for="plan-event">{{ $t('Event') }}</Label>
                    <select
                        id="plan-event"
                        v-model="form.eventId"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                    >
                        <option value="" disabled>
                            {{ $t('Choose an event') }}
                        </option>
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
                    <Label for="plan-name">{{ $t('Name') }}</Label>
                    <Input id="plan-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="plan-floor">{{
                        $t('Floor label (optional)')
                    }}</Label>
                    <Input id="plan-floor" v-model="form.floorLabel" />
                    <InputError :message="form.errors.floorLabel" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="plan-width">{{ $t('Width (mm)') }}</Label>
                        <Input
                            id="plan-width"
                            v-model.number="form.widthMm"
                            type="number"
                            min="1000"
                        />
                        <InputError :message="form.errors.widthMm" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="plan-height">{{ $t('Height (mm)') }}</Label>
                        <Input
                            id="plan-height"
                            v-model.number="form.heightMm"
                            type="number"
                            min="1000"
                        />
                        <InputError :message="form.errors.heightMm" />
                    </div>
                </div>
                <Button type="submit" :disabled="form.processing">
                    {{ $t('Create') }}
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
