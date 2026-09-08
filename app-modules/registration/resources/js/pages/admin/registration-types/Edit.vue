<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    index as typesIndex,
    update as updateType,
} from '@/routes/admin/registration-types';

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
    options: Record<string, string[]>;
    eventPageUrl: string | null;
    registrations: number;
    termsFrozen: boolean;
    appTimezone: string;
};

const props = defineProps<Props>();

/**
 * `datetime-local` wants `YYYY-MM-DDTHH:MM`; the backend sends and accepts a
 * plain `Y-m-d H:i:s` datetime string (#202's round trip, the same pair the
 * policy-version form uses). Converting in both directions here keeps the
 * Data object free of a browser input's formatting quirk, and keeps the
 * string an instant rather than shifting it through a Date object.
 */
function toLocalInput(value: string | null): string {
    return value === null ? '' : value.replace(' ', 'T').slice(0, 16);
}

function toInstant(value: string): string | null {
    return value === '' ? null : `${value.replace('T', ' ')}:00`;
}

/**
 * The window is stored as an instant in the application's timezone, and the
 * value typed here is sent verbatim — so the operator is typing that clock,
 * not their own. Every browser labels a `datetime-local` control as local
 * time and says nothing about which zone that is, so the form names the
 * application's zone on each field and echoes the value back in the
 * operator's own zone underneath. Without that, someone in Europe/Berlin
 * types 18:00 and the landing page announces 20:00 to them.
 *
 * The arithmetic reads `props.appTimezone` rather than assuming UTC (fix
 * round 2, review finding N3): `config('app.timezone')` is a config value,
 * and the hint would otherwise lie the moment it changed. `zonedWallClock()`
 * reads an instant's wall clock in a named zone as if it were UTC, which
 * gives the zone's offset at that moment by subtraction — one iteration,
 * which can be off by an hour for a time inside a DST transition. That is
 * acceptable in a hint that only ever explains the value beside it.
 */
const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

function zonedWallClock(instant: number, timeZone: string): number {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        hour12: false,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date(instant));

    const at = (type: string): string =>
        parts.find((part) => part.type === type)?.value ?? '00';
    const hour = at('hour') === '24' ? '00' : at('hour');

    return Date.parse(
        `${at('year')}-${at('month')}-${at('day')}T${hour}:${at('minute')}:${at('second')}Z`,
    );
}

function inBrowserTimezone(value: string): string {
    if (value === '') {
        return '';
    }

    const wallClock = Date.parse(`${value.replace(' ', 'T')}:00Z`);

    if (Number.isNaN(wallClock)) {
        return '';
    }

    const offset = wallClock - zonedWallClock(wallClock, props.appTimezone);

    return new Date(wallClock + offset).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const salesStartLocal = computed(() => inBrowserTimezone(form.salesStart));
const salesEndLocal = computed(() => inBrowserTimezone(form.salesEnd));

const form = useForm({
    eventId: props.type.eventId,
    name: props.type.name,
    visibility: props.type.visibility,
    approval: props.type.approval,
    settlement: props.type.settlement,
    confirmationGate: props.type.confirmationGate,
    termsDays: props.type.termsDays,
    grants: props.type.grants.map((grant) => ({ ...grant })) as Grant[],
    priceDeclaration: props.type.priceDeclaration
        ? { ...props.type.priceDeclaration }
        : null,
    quotaIds: [...props.type.quotaIds],
    salesStart: toLocalInput(props.type.salesStart),
    salesEnd: toLocalInput(props.type.salesEnd),
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

function addGrant(): void {
    form.grants = [
        ...form.grants,
        { kind: 'admission', role: '', quantity: 1, resourceTypeId: null },
    ];
}

function removeGrant(index: number): void {
    form.grants = form.grants.filter((_, position) => position !== index);
}

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
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Grants</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="text-sm text-text-muted">
                            What a confirmed registration of this type is
                            entitled to. Admission grants become badges;
                            resource claims become bookings.
                        </p>

                        <div
                            v-for="(grant, index) in form.grants"
                            :key="index"
                            class="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-[8rem_1fr_6rem_auto]"
                        >
                            <select
                                v-model="grant.kind"
                                :disabled="props.termsFrozen"
                                aria-label="Grant kind"
                                class="h-9 rounded-md border border-border bg-surface px-3 text-sm disabled:opacity-60"
                            >
                                <option
                                    v-for="value in props.options.grantKind"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ value }}
                                </option>
                            </select>
                            <Input
                                v-model="grant.role"
                                placeholder="Role (visitor, exhibitor, crew…)"
                                :disabled="props.termsFrozen"
                            />
                            <Input
                                v-model.number="grant.quantity"
                                type="number"
                                min="1"
                                :disabled="props.termsFrozen"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="props.termsFrozen"
                                @click="removeGrant(index)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <p
                            v-if="form.grants.length === 0"
                            class="text-sm text-text-muted"
                        >
                            No grants — a registration of this type entitles its
                            holder to nothing yet.
                        </p>

                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="props.termsFrozen"
                                @click="addGrant"
                            >
                                <Plus class="size-4" />
                                Add grant
                            </Button>
                        </div>

                        <InputError :message="form.errors.grants" />
                    </CardContent>
                </Card>
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

                        <div class="grid gap-2">
                            <Label for="type-sales-start">Sale starts</Label>
                            <Input
                                id="type-sales-start"
                                v-model="form.salesStart"
                                type="datetime-local"
                                aria-describedby="type-sales-start-hint"
                            />
                            <p
                                id="type-sales-start-hint"
                                class="text-xs text-text-muted"
                            >
                                Entered in {{ props.appTimezone }}.
                                <template v-if="salesStartLocal">
                                    = {{ salesStartLocal }} in
                                    {{ browserTimezone }}.
                                </template>
                                Before this, the landing page announces the type
                                instead of linking to it.
                            </p>
                            <InputError :message="form.errors.salesStart" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="type-sales-end">Sale ends</Label>
                            <Input
                                id="type-sales-end"
                                v-model="form.salesEnd"
                                type="datetime-local"
                                aria-describedby="type-sales-end-hint"
                            />
                            <p
                                id="type-sales-end-hint"
                                class="text-xs text-text-muted"
                            >
                                Entered in {{ props.appTimezone }}.
                                <template v-if="salesEndLocal">
                                    = {{ salesEndLocal }} in
                                    {{ browserTimezone }}.
                                </template>
                                Must be after the start. After it, the type
                                disappears from the landing page and a new
                                submission is refused; a cart line already
                                placed stays payable.
                            </p>
                            <InputError :message="form.errors.salesEnd" />
                        </div>
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
