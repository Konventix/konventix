<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { resolveRegisteredComponent } from '@/lib/moduleComponents';
import { grantKindLabel } from '../lib/labels';

/**
 * The grants a confirmed registration of a type is entitled to (ADR-0005),
 * extracted from the type editor page (design §7.1). One row per grant:
 * kind, role, quantity. A `seating` grant additionally renders whatever
 * the `registration.type-editor.seating-fields` slot contributes —
 * Resources' category picker and mode select — bound to the grant with
 * `modelValue`/`update:modelValue`, so this module never knows what a
 * category is (ADR-0004). No contribution means Resources is off for the
 * brand, and the row says so.
 */
type Grant = Konventix.Registration.Data.GrantData;

type SlotContribution = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

const model = defineModel<Grant[]>({ required: true });

const props = defineProps<{
    kinds: string[];
    disabled: boolean;
    error?: string;
    seatingSlots: SlotContribution[];
}>();

function emptyGrant(): Grant {
    return {
        kind: 'admission',
        role: '',
        quantity: 1,
        resourceTypeId: null,
        resourceTypeIds: null,
        seatingMode: null,
    } as Grant;
}

function addGrant(): void {
    model.value = [...model.value, emptyGrant()];
}

function removeGrant(index: number): void {
    model.value = model.value.filter((_, position) => position !== index);
}

function replaceGrant(index: number, next: Grant): void {
    model.value = model.value.map((grant, position) =>
        position === index ? next : grant,
    );
}

/** Switching kind resets the seating fields so a stale category list never rides along. */
function changeKind(index: number, kind: string): void {
    const grant = model.value[index];
    const seating = kind === 'seating';

    replaceGrant(index, {
        ...grant,
        kind: kind as Grant['kind'],
        resourceTypeIds: seating ? (grant.resourceTypeIds ?? []) : null,
        seatingMode: seating ? (grant.seatingMode ?? 'pick') : null,
    } as Grant);
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ $t('Grants') }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <p class="text-sm text-text-muted">
                {{
                    $t(
                        'What a confirmed registration of this type is entitled to. Admission grants become badges; resource claims become bookings; a seating grant lets the registrant pick places of the chosen categories after confirmation.',
                    )
                }}
            </p>

            <div
                v-for="(grant, index) in model"
                :key="index"
                class="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-[8rem_1fr_6rem_auto]"
            >
                <select
                    :value="grant.kind"
                    :disabled="props.disabled"
                    :aria-label="$t('Grant kind')"
                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                    @change="
                        changeKind(
                            index,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option
                        v-for="value in props.kinds"
                        :key="value"
                        :value="value"
                    >
                        {{ grantKindLabel(value) }}
                    </option>
                </select>
                <Input
                    v-model="grant.role"
                    :placeholder="$t('Role (visitor, exhibitor, crew…)')"
                    :disabled="props.disabled"
                    :aria-label="$t('Role')"
                />
                <Input
                    v-model.number="grant.quantity"
                    type="number"
                    min="1"
                    :disabled="props.disabled"
                    :aria-label="$t('Quantity')"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="props.disabled"
                    :aria-label="$t('Remove grant')"
                    @click="removeGrant(index)"
                >
                    <Trash2 class="size-3.5" />
                </Button>

                <template v-if="grant.kind === 'seating'">
                    <template
                        v-for="slot in props.seatingSlots"
                        :key="`${slot.module}-${slot.component}`"
                    >
                        <component
                            :is="resolveRegisteredComponent(slot.component)"
                            v-if="resolveRegisteredComponent(slot.component)"
                            class="sm:col-span-4"
                            :model-value="grant"
                            :disabled="props.disabled"
                            v-bind="slot.props"
                            @update:model-value="
                                (next: Grant) => replaceGrant(index, next)
                            "
                        />
                    </template>
                    <p
                        v-if="props.seatingSlots.length === 0"
                        class="text-sm text-warning sm:col-span-4"
                    >
                        {{
                            $t(
                                'Seat categories come from the Resources module, which is switched off for this brand — a seating grant cannot be configured until it is on.',
                            )
                        }}
                    </p>
                </template>
            </div>

            <p v-if="model.length === 0" class="text-sm text-text-muted">
                {{
                    $t(
                        'No grants — a registration of this type entitles its holder to nothing yet.',
                    )
                }}
            </p>

            <div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="props.disabled"
                    @click="addGrant"
                >
                    <Plus class="size-4" />
                    {{ $t('Add grant') }}
                </Button>
            </div>

            <InputError :message="props.error" />
        </CardContent>
    </Card>
</template>
