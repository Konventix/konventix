<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * What names and locates the venue: its name, its public address on this
 * site, and its postal address. One field per model rather than one model
 * for the group, so the page keeps binding straight to its own `useForm`
 * and nothing has to be copied back.
 *
 * An empty slug is not an empty string — `CreateVenue`/`UpdateVenue` derive
 * one from the name when the form leaves it blank, and only null says
 * "derive it".
 */
const name = defineModel<string>('name', { required: true });
const slug = defineModel<string | null>('slug', { required: true });
const addressLine1 = defineModel<string>('addressLine1', { required: true });
const addressLine2 = defineModel<string | null>('addressLine2', {
    required: true,
});
const postalCode = defineModel<string>('postalCode', { required: true });
const city = defineModel<string>('city', { required: true });
const countryCode = defineModel<string>('countryCode', { required: true });

defineProps<{ errors?: Partial<Record<string, string>> }>();

function textOrNull(value: string | number | null): string | null {
    return String(value) || null;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="venue-name">Name</Label>
                <Input id="venue-name" v-model="name" />
                <InputError :message="errors?.name" />
            </div>
            <div class="grid gap-2">
                <Label for="venue-slug">Slug</Label>
                <Input
                    id="venue-slug"
                    :model-value="slug ?? ''"
                    aria-describedby="venue-slug-hint"
                    @update:model-value="slug = textOrNull($event)"
                />
                <p id="venue-slug-hint" class="text-xs text-text-muted">
                    The venue's public address. Left empty, it is derived from
                    the name.
                </p>
                <InputError :message="errors?.slug" />
            </div>
        </div>
        <div class="grid gap-2">
            <Label for="venue-address1">Street and number</Label>
            <Input id="venue-address1" v-model="addressLine1" />
            <InputError :message="errors?.addressLine1" />
        </div>
        <div class="grid gap-2">
            <Label for="venue-address2">Address line 2 (optional)</Label>
            <Input
                id="venue-address2"
                :model-value="addressLine2 ?? ''"
                @update:model-value="addressLine2 = textOrNull($event)"
            />
            <InputError :message="errors?.addressLine2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="venue-postal">Postal code</Label>
                <Input id="venue-postal" v-model="postalCode" />
                <InputError :message="errors?.postalCode" />
            </div>
            <div class="grid gap-2">
                <Label for="venue-city">City</Label>
                <Input id="venue-city" v-model="city" />
                <InputError :message="errors?.city" />
            </div>
            <div class="grid gap-2">
                <Label for="venue-country">Country code</Label>
                <Input id="venue-country" v-model="countryCode" maxlength="2" />
                <InputError :message="errors?.countryCode" />
            </div>
        </div>
    </div>
</template>
