<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, watch } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import DateTimeField from '@/components/datetime/DateTimeField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { toInstant, toLocalDateTimeInput } from '@/lib/datetime';
import {
    index as typesIndex,
    update as updateType,
} from '@/routes/admin/registration-types';
import RegistrationTypeEntryConditionsCard from '../../../components/RegistrationTypeEntryConditionsCard.vue';
import RegistrationTypeGrantsCard from '../../../components/RegistrationTypeGrantsCard.vue';

/**
 * One registration type: its axes (ADR-0005), the grants a confirmed
 * registration is entitled to, its price declaration (ADR-0017), and the
 * quotas it draws on.
 *
 * Once registrations exist, the terms freeze — `UpdateRegistrationType`
 * refuses to change them, because the Actions read the type's *current* axes
 * when a payment lands or an approval is given, so an edit would reinterpret
 * existing registrations rather than migrate them. The form disables exactly
 * those fields rather than letting someone type into a refusal.
 */
type Grant = Konventix.Registration.Data.GrantData;

type Props = {
    type: Konventix.Registration.Data.RegistrationTypeData & {
        id: string;
        brandName: string;
    };
    events: { id: string; name: string; brandId: string }[];
    quotas: { id: string; name: string; capacity: number; consumed: number }[];
    entryConditions: {
        id: string;
        key: string;
        name: string;
        assertionText: string;
        verification: string;
        archived: boolean;
    }[];
    entryConditionAssertions: Record<string, number>;
    alternativeTypes: { id: string; name: string }[];
    options: Record<string, string[]>;
    eventPageUrl: string | null;
    registrations: number;
    termsFrozen: boolean;
    seatingSlots: {
        module: string;
        component: string;
        props: Record<string, unknown>;
    }[];
};

const props = defineProps<Props>();

/**
 * The sales window is an instant, and a `datetime-local` control is a wall
 * clock — so the conversion between them belongs in the browser, which is
 * the only party that knows the zone (#237, the rule #202 settled for the
 * policy editor and `@/lib/datetime` now owns for both).
 *
 * What it replaced: the page sent the typed digits through as
 * `Y-m-d H:i:s` and the backend read them in `config('app.timezone')`. An
 * operator in Europe/Berlin who typed 10:17 got a window that opened at
 * 12:17 on their own clock, and the landing page said so. The hint below
 * used to name the *application's* zone and echo the value converted out of
 * it, which confirmed the wrong number rather than catching it; it now names
 * the operator's own zone, because that is the clock the field speaks.
 *
 * A wall clock inside a DST gap — 02:30 on the spring-forward night — is
 * shifted to the hour that does exist, so the echo differs from what was
 * typed. There is no other honest answer for a sales window, and the hint
 * says so rather than letting the field look like it lost the value.
 *
 * The hint, the echo and the zone-naming are `DateTimeField`'s since #242 —
 * three pages had grown three versions of the same field.
 */
const form = useForm({
    eventId: props.type.eventId,
    name: props.type.name,
    visibility: props.type.visibility,
    approval: props.type.approval,
    settlement: props.type.settlement,
    confirmationGate: props.type.confirmationGate,
    termsDays: props.type.termsDays,
    maxQuantityPerRegistration: props.type.maxQuantityPerRegistration,
    grants: props.type.grants.map((grant) => ({ ...grant })) as Grant[],
    priceDeclaration: props.type.priceDeclaration
        ? { ...props.type.priceDeclaration }
        : null,
    quotaIds: [...props.type.quotaIds],
    // Not frozen (D-REG-19): a sold type still accepts a new condition, and
    // the card warns about what that leaves *not asserted* before saving.
    entryConditionIds: [...props.type.entryConditionIds],
    alternativeRegistrationTypeId: props.type.alternativeRegistrationTypeId,
    salesStart: toLocalDateTimeInput(props.type.salesStart),
    salesEnd: toLocalDateTimeInput(props.type.salesEnd),
});

useUnsavedChanges(() => form.isDirty);

const needsPrice = computed(
    () => form.settlement === 'instant' || form.settlement === 'invoice',
);

watch(needsPrice, (priced) => {
    if (props.termsFrozen) {
        return;
    }

    form.priceDeclaration = priced
        ? (form.priceDeclaration ?? {
              unitAmountNet: '0.00',
              taxClass: 'standard',
              display: 'net',
          })
        : null;
});

function toggleQuota(quotaId: string, checked: boolean): void {
    form.quotaIds = checked
        ? [...new Set([...form.quotaIds, quotaId])]
        : form.quotaIds.filter((id) => id !== quotaId);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        salesStart: toInstant(data.salesStart),
        salesEnd: toInstant(data.salesEnd),
        // An emptied number input is "no ceiling of its own", which is one
        // (Task 19) — not the empty string the browser hands over.
        maxQuantityPerRegistration:
            data.maxQuantityPerRegistration === null ||
            (data.maxQuantityPerRegistration as unknown as string) === ''
                ? null
                : Number(data.maxQuantityPerRegistration),
    })).put(updateType(props.type.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${props.type.name} — Registration type`" />

    <div class="flex flex-col gap-6">
        <AdminPageHeader
            :title="props.type.name"
            :description="props.type.brandName"
        >
            <span
                v-if="props.termsFrozen"
                class="inline-flex rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning"
            >
                {{ props.registrations }} registrations — terms frozen
            </span>
            <Button variant="outline" as-child>
                <Link :href="typesIndex().url">
                    <ArrowLeft class="size-4" />
                    All types
                </Link>
            </Button>
        </AdminPageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>The offer</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p
                            v-if="props.termsFrozen"
                            class="rounded-md border border-warning/30 bg-warning/10 p-3 text-sm text-text"
                        >
                            People have already registered under these terms.
                            Name, visibility and quota links stay editable;
                            everything else would reinterpret their
                            registrations, so it needs a new type.
                        </p>

                        <div class="grid gap-2">
                            <Label for="type-name">Name</Label>
                            <Input id="type-name" v-model="form.name" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="type-event">Event</Label>
                                <select
                                    id="type-event"
                                    v-model="form.eventId"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="event in props.events"
                                        :key="event.id"
                                        :value="event.id"
                                    >
                                        {{ event.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.eventId" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-visibility">Visibility</Label>
                                <select
                                    id="type-visibility"
                                    v-model="form.visibility"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                                >
                                    <option
                                        v-for="value in props.options
                                            .visibility"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.visibility" />
                                <p class="text-sm text-text-muted">
                                    <template
                                        v-if="form.visibility === 'public'"
                                        >This type is offered on the event's own
                                        public page, never in the shop, and
                                        appears there as soon as you
                                        save.</template
                                    >
                                    <template
                                        v-else-if="
                                            form.visibility === 'invite_only'
                                        "
                                        >This type is not offered on the event's
                                        public page. It is reachable by
                                        invitation only — an invited person gets
                                        their own link.</template
                                    >
                                    <template v-else
                                        >This type is not offered on the event's
                                        public page. It is reachable only by
                                        someone who has the link.</template
                                    >
                                    <a
                                        v-if="
                                            props.eventPageUrl &&
                                            form.visibility === 'public'
                                        "
                                        :href="props.eventPageUrl"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-accent hover:underline"
                                    >
                                        Open the event page
                                    </a>
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-approval">Approval</Label>
                                <select
                                    id="type-approval"
                                    v-model="form.approval"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="value in props.options.approval"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.approval" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-settlement">Settlement</Label>
                                <select
                                    id="type-settlement"
                                    v-model="form.settlement"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="value in props.options
                                            .settlement"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.settlement" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-gate">
                                    Confirmation gate
                                </Label>
                                <select
                                    id="type-gate"
                                    v-model="form.confirmationGate"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="value in props.options
                                            .confirmationGate"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <InputError
                                    :message="form.errors.confirmationGate"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-terms">Payment terms</Label>
                                <Input
                                    id="type-terms"
                                    v-model.number="form.termsDays"
                                    type="number"
                                    min="0"
                                    :disabled="props.termsFrozen"
                                />
                                <p class="text-xs text-text-muted">
                                    Days until an invoice is due.
                                </p>
                                <InputError :message="form.errors.termsDays" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="type-max-quantity"
                                    >Maximum per registration</Label
                                >
                                <Input
                                    id="type-max-quantity"
                                    v-model.number="
                                        form.maxQuantityPerRegistration
                                    "
                                    type="number"
                                    min="1"
                                    placeholder="1"
                                />
                                <p class="text-xs text-text-muted">
                                    How many units one person may buy in a
                                    single registration. Empty means one, and
                                    the form then offers no choice.
                                </p>
                                <InputError
                                    :message="
                                        form.errors.maxQuantityPerRegistration
                                    "
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <RegistrationTypeGrantsCard
                    v-model="form.grants"
                    :kinds="props.options.grantKind"
                    :disabled="props.termsFrozen"
                    :error="form.errors.grants"
                    :seating-slots="props.seatingSlots"
                />

                <RegistrationTypeEntryConditionsCard
                    v-model="form.entryConditionIds"
                    v-model:alternative-type-id="
                        form.alternativeRegistrationTypeId
                    "
                    :conditions="props.entryConditions"
                    :assertion-counts="props.entryConditionAssertions"
                    :saved-ids="props.type.entryConditionIds"
                    :registrations="props.registrations"
                    :alternative-types="props.alternativeTypes"
                    :error="form.errors.entryConditionIds"
                    :alternative-error="
                        form.errors.alternativeRegistrationTypeId
                    "
                />
            </div>

            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Price</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p v-if="!needsPrice" class="text-sm text-text-muted">
                            A
                            <span class="font-medium">
                                {{ form.settlement }}
                            </span>
                            type carries no price: no order, no invoice.
                        </p>

                        <template v-if="form.priceDeclaration">
                            <div class="grid gap-2">
                                <Label for="price-amount">Net amount</Label>
                                <Input
                                    id="price-amount"
                                    v-model="
                                        form.priceDeclaration.unitAmountNet
                                    "
                                    :disabled="props.termsFrozen"
                                    placeholder="250.00"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="price-tax">Tax class</Label>
                                <select
                                    id="price-tax"
                                    v-model="form.priceDeclaration.taxClass"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="value in props.options.taxClass"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                                <p class="text-xs text-text-muted">
                                    The class, not a rate — Commerce resolves
                                    the rate at settlement.
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="price-display">Display</Label>
                                <select
                                    id="price-display"
                                    v-model="form.priceDeclaration.display"
                                    :disabled="props.termsFrozen"
                                    class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                                >
                                    <option
                                        v-for="value in props.options
                                            .priceDisplay"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ value }}
                                    </option>
                                </select>
                            </div>
                        </template>

                        <InputError :message="form.errors.priceDeclaration" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Sales window</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            When this type may be bought. Leave a field empty
                            for no bound — the start is inclusive, the end is
                            not.
                        </p>

                        <DateTimeField
                            id="type-sales-start"
                            v-model="form.salesStart"
                            label="Sale starts"
                            hint="Before this, the landing page announces the type instead of linking to it."
                            :error="form.errors.salesStart"
                        />

                        <DateTimeField
                            id="type-sales-end"
                            v-model="form.salesEnd"
                            label="Sale ends"
                            hint="Must be after the start. After it, the type disappears from the landing page and a new submission is refused; a cart line already placed stays payable."
                            :error="form.errors.salesEnd"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Quotas</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p
                            v-if="props.quotas.length === 0"
                            class="text-sm text-text-muted"
                        >
                            This brand has no quotas yet.
                        </p>

                        <div
                            v-for="quota in props.quotas"
                            :key="quota.id"
                            class="flex items-center gap-2"
                        >
                            <Checkbox
                                :id="`quota-${quota.id}`"
                                :model-value="form.quotaIds.includes(quota.id)"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        toggleQuota(quota.id, value === true)
                                "
                            />
                            <Label
                                :for="`quota-${quota.id}`"
                                class="flex flex-1 justify-between gap-2"
                            >
                                <span>{{ quota.name }}</span>
                                <span class="text-xs text-text-muted">
                                    {{ quota.consumed }} / {{ quota.capacity }}
                                </span>
                            </Label>
                        </div>

                        <InputError :message="form.errors.quotaIds" />
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                    <span
                        v-if="form.isDirty"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </div>
        </form>
    </div>
</template>
