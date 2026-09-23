<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { VenuePlaceForm } from '../../lib/venueForm';
import { emptyPlace } from '../../lib/venueForm';

/**
 * The repeatable rows behind a venue's parking spots and its loading zones
 * — one component for both, because the two lists are the same shape
 * (`VenuePlaceData`: a label, an optional address, an optional coordinate
 * pair) and only differ in what they are called.
 *
 * Coordinates are optional on purpose: a place with none is a label and an
 * address, a place with both gets an "Open in maps" link on the public
 * page.
 */
const model = defineModel<VenuePlaceForm[]>({ required: true });

const props = defineProps<{
    /** What one row is, in the singular — "Parking spot", "Loading zone". */
    itemLabel: string;
    /** Server-side errors keyed by index, e.g. `parkingSpots.0.label`. */
    errors?: Partial<Record<string, string>>;
    fieldPrefix: string;
}>();

const uid = useId();

function fieldId(index: number, field: string): string {
    return `${uid}-${props.fieldPrefix}-${index}-${field}`;
}

function errorFor(index: number, field: string): string | undefined {
    return props.errors?.[`${props.fieldPrefix}.${index}.${field}`];
}

function add(): void {
    model.value = [...model.value, emptyPlace()];
}

function remove(index: number): void {
    model.value = model.value.filter((_, position) => position !== index);
}

/**
 * A coordinate input is a number field whose empty state is "not pinned",
 * not zero — the Gulf of Guinea is a real place and no venue parks there.
 */
function toCoordinate(value: string | number | null): number | null {
    const text = String(value).trim();

    return text === '' || Number.isNaN(Number(text)) ? null : Number(text);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="model.length === 0" class="text-sm text-text-muted">
            {{ $t('None — the public page shows no such section.') }}
        </p>

        <div
            v-for="(place, index) in model"
            :key="index"
            class="flex flex-col gap-3 rounded-md border border-border p-3"
        >
            <div class="flex items-end gap-2">
                <div class="grid flex-1 gap-2">
                    <Label :for="fieldId(index, 'label')">
                        {{ props.itemLabel }}
                    </Label>
                    <Input
                        :id="fieldId(index, 'label')"
                        v-model="place.label"
                        :aria-invalid="
                            errorFor(index, 'label') ? 'true' : undefined
                        "
                        :aria-describedby="
                            errorFor(index, 'label')
                                ? fieldId(index, 'label-error')
                                : undefined
                        "
                    />
                    <InputError
                        :id="fieldId(index, 'label-error')"
                        :message="errorFor(index, 'label')"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="
                        $t('Remove :item :number', {
                            item: props.itemLabel,
                            number: String(index + 1),
                        })
                    "
                    @click="remove(index)"
                >
                    <Trash2 class="size-4 text-danger" />
                </Button>
            </div>

            <div class="grid gap-2">
                <Label :for="fieldId(index, 'address')">
                    {{ $t('Address (optional)') }}
                </Label>
                <Input
                    :id="fieldId(index, 'address')"
                    :model-value="place.address ?? ''"
                    @update:model-value="place.address = String($event) || null"
                />
                <InputError :message="errorFor(index, 'address')" />
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label :for="fieldId(index, 'latitude')">
                        {{ $t('Latitude (optional)') }}
                    </Label>
                    <Input
                        :id="fieldId(index, 'latitude')"
                        type="number"
                        step="any"
                        :model-value="place.latitude ?? ''"
                        @update:model-value="
                            place.latitude = toCoordinate($event)
                        "
                    />
                    <InputError :message="errorFor(index, 'latitude')" />
                </div>
                <div class="grid gap-2">
                    <Label :for="fieldId(index, 'longitude')">
                        {{ $t('Longitude (optional)') }}
                    </Label>
                    <Input
                        :id="fieldId(index, 'longitude')"
                        type="number"
                        step="any"
                        :model-value="place.longitude ?? ''"
                        @update:model-value="
                            place.longitude = toCoordinate($event)
                        "
                    />
                    <InputError :message="errorFor(index, 'longitude')" />
                </div>
            </div>
        </div>

        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="self-start"
            @click="add"
        >
            <Plus class="size-4" />
            {{ $t('Add :item', { item: props.itemLabel }) }}
        </Button>
    </div>
</template>
