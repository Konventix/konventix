import { trans } from 'laravel-vue-i18n';

/**
 * The words Registration prints for its enum values (ADR-0068). The raw
 * values (`submitted`, `operator_checked`) are wire values, not text: a page
 * that printed them showed English to every locale. Each label is looked up
 * when it is asked for, never at import time, because the catalog is loaded
 * after the modules are imported.
 *
 * An unknown value falls through unchanged — a case added on the server
 * shows up raw rather than as an empty badge.
 */
function lookup(labels: Record<string, string>, value: string): string {
    return labels[value] ?? value;
}

/** `RegistrationStatus`. */
export function registrationStatusLabel(status: string): string {
    return lookup(
        {
            submitted: trans('Submitted'),
            approved: trans('Approved'),
            rejected: trans('Rejected'),
            confirmed: trans('Confirmed'),
            cancelled: trans('Cancelled'),
        },
        status,
    );
}

/** `PaymentStatus`. */
export function paymentStatusLabel(status: string): string {
    return lookup(
        {
            none: trans('No payment'),
            pending: trans('Pending'),
            paid: trans('Paid'),
            overdue: trans('Overdue'),
        },
        status,
    );
}

/** `EntryConditionAssertionState`. */
export function assertionStateLabel(state: string): string {
    return lookup(
        {
            asserted: trans('Declared'),
            verified: trans('Verified'),
            refused: trans('Refused'),
            waived: trans('Waived'),
        },
        state,
    );
}

/** `EntryConditionVerification`: how a declaration is checked. */
export function verificationLabel(verification: string): string {
    return lookup(
        {
            self_declared: trans('Self-declared'),
            document_at_door: trans('Document at the door'),
            operator_checked: trans('Checked by the operator'),
        },
        verification,
    );
}

/**
 * `EntryConditionDecisionSource`: where a declaration was decided, as the
 * phrase that follows "decided …".
 */
export function decisionSourceLabel(source: string): string {
    return lookup(
        {
            console: trans('at the console'),
            door: trans('at the door'),
        },
        source,
    );
}

/** `EntryConditionReasonCode`: why a declaration was refused or waived. */
export function entryConditionReasonLabel(reason: string): string {
    return lookup(
        {
            document_missing: trans('Document missing'),
            document_invalid: trans('Document invalid'),
            operator_waived: trans('Waived by the operator'),
        },
        reason,
    );
}

/** `RegistrationSettlement`. */
export function settlementLabel(settlement: string): string {
    return lookup(
        {
            free: trans('Free of charge'),
            instant: trans('Instant payment'),
            invoice: trans('Invoice'),
            external: trans('External'),
        },
        settlement,
    );
}

/** `RegistrationApproval`. */
export function approvalLabel(approval: string): string {
    return lookup(
        {
            none: trans('No approval'),
            required: trans('Approval required'),
        },
        approval,
    );
}

/** `ConfirmationGate`. */
export function confirmationGateLabel(gate: string): string {
    return lookup(
        {
            on_submit: trans('On submit'),
            on_approval: trans('On approval'),
            on_payment: trans('On payment'),
        },
        gate,
    );
}

/** `RegistrationVisibility`. */
export function visibilityLabel(visibility: string): string {
    return lookup(
        {
            public: trans('Public'),
            link: trans('Link only'),
            invite_only: trans('Invite only'),
        },
        visibility,
    );
}

/** `GrantKind`. */
export function grantKindLabel(kind: string): string {
    return lookup(
        {
            admission: trans('Admission'),
            resource_claim: trans('Resource claim'),
            seating: trans('Seating'),
        },
        kind,
    );
}

/** `SeatingMode`. */
export function seatingModeLabel(mode: string): string {
    return lookup(
        {
            pick: trans('Registrant picks'),
            assign: trans('Operator assigns'),
        },
        mode,
    );
}

/** `QuantityReductionReason`. */
export function quantityReductionReasonLabel(reason: string): string {
    return lookup(
        {
            'registrant-request': trans('The registrant asked for it'),
            'duplicate-booking': trans('Duplicate booking'),
            'operator-correction': trans('Operator correction'),
            goodwill: trans('Goodwill'),
        },
        reason,
    );
}

/** `TaxClass`: a type's VAT rate class. */
export function taxClassLabel(taxClass: string): string {
    return lookup(
        {
            standard: trans('Standard rate'),
            reduced: trans('Reduced rate'),
        },
        taxClass,
    );
}

/** `PriceDisplay`: which of the two amounts a type's price shows. */
export function priceDisplayLabel(display: string): string {
    return lookup(
        {
            gross: trans('Gross'),
            net: trans('Net'),
        },
        display,
    );
}
