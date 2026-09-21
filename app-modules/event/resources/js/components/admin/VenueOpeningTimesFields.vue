<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { useId } from 'vue';
import TimeField from '@/components/datetime/TimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { OpeningPeriodForm } from '../../lib/venueForm';
import { emptyOpeningPeriod, WEEKDAYS } from '../../lib/venueForm';

/**
 * When the venue's site is open, as the repeatable rows behind
 * `OpeningPeriodData` — a weekday and a pair of times, one row per period.
 * Two rows on the same day are how a site that closes for lunch is said,
 * which is why this is a list and not a seven-row grid.
 *
 * The times are wall clocks on the site's own door, not instants: they
 * carry no date to hang an offset on, so `TimeField` is used without
 * `zoned` and nothing is converted on the way in or out (.ai/rules/js.md).
 *
 * Errors arrive per row from the server (`openingTimes.0.opensAt`), the
 * same shape `VenuePlacesFields` reads.
 */
const model = defineModel<OpeningPeriodForm[]>({ required: true });

const props = defineProps<{
    /** Server-side errors keyed by index, e.g. `openingTimes.0.opensAt`. */
    errors?: Partial<Record<string, string>>;
}>();

const uid = useId();

function fieldId(index: number, field: string): string {
    return `${uid}-openingTimes-${index}-${field}`;
}

function errorFor(index: number, field: string): string | undefined {
    return props.errors?.[`openingTimes.${index}.${field}`];
}

function add(): void {
    model.value = [...model.value, emptyOpeningPeriod(model.value)];
}

function remove(index: number): void {
    model.value = model.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="model.length === 0" class="text-sm text-text-muted">
            None — the public page shows no opening times.
        </p>

        <div
            v-for="(period, index) in model"
            :key="index"
            class="flex flex-col gap-3 rounded-md border border-border p-3 sm:flex-row sm:items-start sm:gap-4"
        >
            <div class="grid flex-1 gap-2">
                <Label :for="fieldId(index, 'dayOfWeek')">Day</Label>
                <Select
                    :model-value="String(period.dayOfWeek)"
                    @update:model-value="period.dayOfWeek = Number($event)"
                >
                    <SelectTrigger :id="fieldId(index, 'dayOfWeek')">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="day in WEEKDAYS"
                            :key="day.value"
                            :value="String(day.value)"
                        >
                            {{ day.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errorFor(index, 'dayOfWeek')" />
            </div>

            <div class="flex-1">
                <TimeField
                    :id="fieldId(index, 'opensAt')"
                    v-model="period.opensAt"
                    label="Opens"
                    :error="errorFor(index, 'opensAt')"
                />
            </div>

            <div class="flex-1">
                <TimeField
                    :id="fieldId(index, 'closesAt')"
                    v-model="period.closesAt"
                    label="Closes"
                    :error="errorFor(index, 'closesAt')"
                />
            </div>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="self-start sm:mt-7"
                :aria-label="`Remove opening period ${index + 1}`"
                @click="remove(index)"
            >
                <Trash2 class="size-4 text-danger" />
            </Button>
        </div>

        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="self-start"
            @click="add"
        >
            <Plus class="size-4" />
            Add opening period
        </Button>
    </div>
</template>
