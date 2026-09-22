<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { refund } from '@/routes/admin/orders';

/**
 * The console's refund form (#73, ADR-0037) and, since D-0.8.2-1, its
 * line-and-quantity picker (#7).
 *
 * Extracted from `admin/orders/Show.vue` rather than grown inside it: the
 * page was already well past the ~300-line mark `CLAUDE_extra.md` sets, and
 * the picker is the largest single thing on it. Everything the dialog owns —
 * the form, the reason vocabulary, the selection, where focus lands
 * afterwards — lives here; the page passes the order, its lines and the
 * unapplied payment, and re-renders when Inertia sends fresh props.
 *
 * **Quantities, never amounts.** A partial refund is expressed by picking
 * lines and how many units of each (D-0.8.2-1). There is no amount field and
 * deliberately no client-side money arithmetic: the server resolves the
 * selection through `TaxCalculator` at each line's own snapshotted rate, and
 * a browser that computed its own total would sooner or later show an
 * operator a figure the Gutschrift then contradicts. The confirmation the
 * operator gets is the credit note itself, which lists exactly what was
 * credited.
 *
 * Part of a single unit cannot be asked for here — the input is an integer
 * of at least one — and the server refuses it too rather than rounding it
 * into a document that cannot be corrected (ADR-0031).
 */
type RefundableLine = Konventix.Commerce.Data.OrderCreditLineData;

const props = defineProps<{
    open: boolean;
    order: {
        id: string;
        status: string;
        currency: string;
        totalGross: string;
    };
    lines: RefundableLine[];
    /** ADR-0057 point 9 / #6 — money the order could never take. */
    unapplied: boolean;
    /**
     * A partial refund is a credit note, and a credit note corrects an
     * invoice (ADR-0031). An order that was never invoiced can still be
     * refunded whole — that is money moving without paperwork — but there
     * is nothing for a *subset* to correct, and no `credit_note_lines` row
     * would record what was credited, so a second partial could not be
     * checked against the first. The picker is therefore offered only where
     * an invoice exists.
     */
    creditable: boolean;
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

/**
 * Where what it collects ends up, precisely: the note is stored on the
 * credit-note record (`credit_notes.reason_note`) and the code travels on
 * `OrderRefunded` — and both are written in full to the audit entry, which
 * is where an operator reads back who refunded and why. Neither is printed
 * on the Gutschrift itself: that document says "Grund: Erstattung" for
 * every refund (`credit-notes/document.blade.php` renders `reason_code`,
 * which `RecordRefund` always sets to `refund`). Printing the operator's
 * reason on the document is a follow-up, not something this dialog may
 * promise.
 *
 * The vocabulary is `Konventix.Commerce.Enums.RefundReason`, the generated
 * type, and not a hand-kept copy of it (ADR-0009, whole-branch review
 * finding 10): a `Record` keyed by that type is exhaustive — adding,
 * renaming or removing a case fails `npm run types:check` here rather than
 * reaching an operator as a form error.
 */
const refundReasonLabels: Record<
    Konventix.Commerce.Enums.RefundReason,
    string
> = {
    'customer-request': 'The customer asked for it',
    'event-cancelled': 'The event was cancelled',
    'duplicate-payment': 'Paid twice',
    goodwill: 'Goodwill',
};

const refundReasons = Object.entries(refundReasonLabels).map(
    ([value, label]) => ({
        value: value as Konventix.Commerce.Enums.RefundReason,
        label,
    }),
);

type LineSelection = { orderLineId: string; quantity: number };

/**
 * Deliberately no pre-picked reason. `#[Required]` on
 * `RefundOrderData::$reasonCode` refuses an empty submission and the 422
 * lands on the field's own `InputError` (ADR-0055): defaulting to the most
 * common answer would let an irreversible money movement be justified by an
 * opinion nobody formed. It also keeps `isDirty` honest, so ADR-0038's
 * "Unsaved changes" note appears exactly when something has been entered.
 */
const form = useForm<{
    reasonCode: Konventix.Commerce.Enums.RefundReason | null;
    reasonNote: string | null;
    lines: LineSelection[];
}>({
    reasonCode: null,
    reasonNote: null,
    lines: [],
});

/**
 * Whole order or a selection. "Whole" is the default and the only choice
 * the dialog had before this: an operator who opens it to give everything
 * back should not have to tick every line to say so, and the whole refund
 * is also the one that releases the places the order paid for.
 */
const scope = ref<'whole' | 'lines'>('whole');

/** Ticked lines, and how many units of each — keyed by order line id. */
const picked = ref<Record<string, number>>({});

const creditableLines = computed(() =>
    props.lines.filter((line) => line.remainingQuantity > 0),
);

/**
 * The picker is worth showing only where there is something to pick: an
 * order with one line, all of it still uncredited, has no partial refund
 * that is not simply the whole order.
 */
const canPickLines = computed(
    () =>
        props.creditable &&
        !props.unapplied &&
        props.order.status === 'paid' &&
        creditableLines.value.length > 0,
);

const selection = computed<LineSelection[]>(() =>
    Object.entries(picked.value)
        .filter(([, quantity]) => quantity > 0)
        .map(([orderLineId, quantity]) => ({ orderLineId, quantity })),
);

const nothingPicked = computed(
    () => scope.value === 'lines' && selection.value.length === 0,
);

/**
 * Every refusal `RefundOrder` makes — the ADR-0037 check-in guard, "only a
 * paid order can be refunded", a line already credited in full, a provider
 * with nothing refundable — arrives as a validation error under `order`,
 * which is not one of this form's own fields, so it is read by name like
 * `products/Index.vue` reads `brand`.
 */
const refusal = computed(() => (form.errors as Record<string, string>).order);

/**
 * Anything the server said about the selection itself — `lines`, or a
 * nested `lines.0.quantity`. Shown once above the picker rather than per
 * row: the indices in those keys are the request's, and a row that has
 * since been re-rendered cannot be matched to one reliably.
 */
const selectionError = computed(() => {
    const errors = form.errors as Record<string, string>;

    const key = Object.keys(errors).find(
        (name) => name === 'lines' || name.startsWith('lines.'),
    );

    return key === undefined ? undefined : errors[key];
});

function toggleLine(line: RefundableLine, checked: boolean): void {
    if (checked) {
        picked.value = { ...picked.value, [line.id]: line.remainingQuantity };

        return;
    }

    const next = { ...picked.value };
    delete next[line.id];
    picked.value = next;
}

function setQuantity(line: RefundableLine, value: string): void {
    const parsed = Number.parseInt(value, 10);

    if (Number.isNaN(parsed)) {
        return;
    }

    picked.value = {
        ...picked.value,
        [line.id]: Math.min(Math.max(parsed, 1), line.remainingQuantity),
    };
}

// Only while the dialog is open (ADR-0038): a closed dialog's untouched
// draft is not unsaved work, the same scoping `products/Index.vue` uses.
useUnsavedChanges(
    () => props.open && (form.isDirty || selection.value.length > 0),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            return;
        }

        form.reset();
        form.clearErrors();
        scope.value = 'whole';
        picked.value = {};
    },
);

function submit(): void {
    form
        // A textarea that was typed into and then emptied posts `''`; the
        // note the credit note keeps is either words or nothing. The
        // selection travels only when the operator chose one — an empty
        // array is the whole order, which is what `RefundOrderData` reads
        // it as.
        .transform((data) => ({
            ...data,
            reasonNote: data.reasonNote || null,
            lines: scope.value === 'lines' ? selection.value : [],
        }))
        .post(refund(props.order.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('update:open', false);
                returnFocus();
            },
        });
}

/**
 * Where the keyboard goes once the refund has gone through (ADR-0055 point
 * 1, whole-branch review finding 6).
 *
 * The dialog restores focus to the trigger it captured, and that trigger
 * disappears when a whole refund flips the order's status — reka-ui then
 * calls `.focus()` on a detached node and focus lands on `<body>`, which
 * silently returns a keyboard operator to the top of the document after the
 * console's most consequential action. `main#main-content` is the layout's
 * own landing place for exactly this (`.ai/rules/js.md`, #181), it carries
 * `tabindex="-1"`, and it is where the refreshed order is.
 *
 * After `nextTick`, because the props that remove the trigger have not been
 * applied when `onSuccess` runs.
 */
function returnFocus(): void {
    void nextTick(() => {
        document.getElementById('main-content')?.focus();
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    unapplied ? 'Refund unapplied payment' : 'Refund order'
                }}</DialogTitle>
                <DialogDescription>
                    Send money back through the account that took it. Where an
                    invoice exists it is credited. The reason and the note below
                    are recorded against this order for you and the next
                    operator — they are not printed on the credit note the payer
                    receives. This cannot be undone here.
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <InputError :message="refusal" />

                <fieldset v-if="canPickLines" class="grid gap-2">
                    <legend class="text-sm font-medium">What goes back</legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="scope"
                            type="radio"
                            name="refund-scope"
                            value="whole"
                        />
                        The whole order ({{ order.totalGross }}
                        {{ order.currency }})
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="scope"
                            type="radio"
                            name="refund-scope"
                            value="lines"
                        />
                        Selected lines only
                    </label>
                </fieldset>

                <div v-if="scope === 'lines'" class="grid gap-3">
                    <InputError :message="selectionError" />
                    <p class="text-sm text-text-muted">
                        Pick whole units. The net amount, the VAT and the total
                        are computed per line at the rate each line was charged
                        at, and the credit note lists exactly what went back.
                    </p>
                    <div
                        v-for="line in lines"
                        :key="line.id"
                        class="flex items-start gap-3 rounded-md border border-border p-3"
                    >
                        <Checkbox
                            :id="`refund-line-${line.id}`"
                            :model-value="picked[line.id] !== undefined"
                            :disabled="line.remainingQuantity === 0"
                            class="mt-1"
                            @update:model-value="
                                (value: boolean | 'indeterminate') =>
                                    toggleLine(line, value === true)
                            "
                        />
                        <div class="flex-1">
                            <Label
                                :for="`refund-line-${line.id}`"
                                class="font-medium"
                            >
                                {{ line.label }}
                            </Label>
                            <p class="text-sm text-text-muted">
                                {{ line.quantity }} ×
                                {{ line.unitAmountNet }}
                                {{ order.currency }} net · {{ line.taxRate }} %
                                VAT
                                <template v-if="line.creditedQuantity > 0">
                                    · {{ line.creditedQuantity }} already
                                    credited
                                </template>
                                <template v-if="line.remainingQuantity === 0">
                                    · nothing left to credit
                                </template>
                            </p>
                        </div>
                        <div
                            v-if="picked[line.id] !== undefined"
                            class="grid gap-1"
                        >
                            <Label
                                :for="`refund-quantity-${line.id}`"
                                class="sr-only"
                            >
                                Units of {{ line.label }} to credit
                            </Label>
                            <Input
                                :id="`refund-quantity-${line.id}`"
                                type="number"
                                inputmode="numeric"
                                step="1"
                                min="1"
                                :max="line.remainingQuantity"
                                class="w-20"
                                :value="picked[line.id]"
                                @input="
                                    (event: Event) =>
                                        setQuantity(
                                            line,
                                            (event.target as HTMLInputElement)
                                                .value,
                                        )
                                "
                            />
                            <span class="text-xs text-text-muted"
                                >of {{ line.remainingQuantity }}</span
                            >
                        </div>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="refund-reason">Reason</Label>
                    <select
                        id="refund-reason"
                        v-model="form.reasonCode"
                        class="h-9 rounded-md border border-border bg-surface px-3 text-sm"
                        :aria-invalid="
                            form.errors.reasonCode ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.reasonCode
                                ? 'refund-reason-error'
                                : undefined
                        "
                    >
                        <!-- Disabled, so it can be read but not chosen: the
                             operator picks a reason or the server refuses
                             the refund. -->
                        <option :value="null" disabled>Choose a reason</option>
                        <option
                            v-for="reason in refundReasons"
                            :key="reason.value"
                            :value="reason.value"
                        >
                            {{ reason.label }}
                        </option>
                    </select>
                    <InputError
                        id="refund-reason-error"
                        :message="form.errors.reasonCode"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="refund-note">Note (optional)</Label>
                    <textarea
                        id="refund-note"
                        v-model="form.reasonNote"
                        rows="3"
                        class="rounded-md border border-border bg-surface px-3 py-2 text-sm"
                        :aria-invalid="
                            form.errors.reasonNote ? true : undefined
                        "
                        :aria-describedby="
                            form.errors.reasonNote
                                ? 'refund-note-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="refund-note-error"
                        :message="form.errors.reasonNote"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing || nothingPicked"
                    >
                        <template v-if="scope === 'lines'">
                            Refund the selected lines
                        </template>
                        <template v-else>
                            Refund {{ order.totalGross }} {{ order.currency }}
                        </template>
                    </Button>
                    <span
                        v-if="form.isDirty || selection.length > 0"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Unsaved changes
                    </span>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
