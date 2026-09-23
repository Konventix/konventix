import { trans } from 'laravel-vue-i18n';

/**
 * The words commerce prints for its enum values (ADR-0068). The raw enum values
 * (`payment_reversed`, `succeeded`) are wire values, not text: a page that
 * printed them showed English to every locale. Each label is looked up when
 * it is asked for, never at import time, because the catalog is loaded
 * after the modules are imported.
 *
 * An unknown value falls through unchanged — a status added on the server
 * shows up raw rather than as an empty badge.
 */
export function orderStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        pending: trans('Pending'),
        paid: trans('Paid'),
        cancelled: trans('Cancelled'),
        refunded: trans('Refunded'),
        // Not a variant of "paid": was paid, and is owed again (ADR-0057
        // point 3).
        payment_reversed: trans('Payment reversed'),
    };

    return labels[status] ?? status;
}

export function paymentStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        pending: trans('Pending'),
        succeeded: trans('Succeeded'),
        failed: trans('Failed'),
        expired: trans('Expired'),
        reversed: trans('Reversed'),
    };

    return labels[status] ?? status;
}

export function reversalStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        open: trans('Open'),
        final: trans('Final'),
        withdrawn: trans('Withdrawn'),
    };

    return labels[status] ?? status;
}

/** What an order settles: one registration, or a whole cart (ADR-0047). */
export function settlesTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        registration: trans('Registration'),
        cart: trans('Cart'),
    };

    return labels[type] ?? type;
}

/** A product's VAT rate class (`TaxClass`). */
export function taxClassLabel(taxClass: string): string {
    const labels: Record<string, string> = {
        standard: trans('Standard rate'),
        reduced: trans('Reduced rate'),
    };

    return labels[taxClass] ?? taxClass;
}

/** Which of the two amounts a product's price shows (`PriceDisplay`). */
export function priceDisplayLabel(priceDisplay: string): string {
    const labels: Record<string, string> = {
        gross: trans('gross'),
        net: trans('net'),
    };

    return labels[priceDisplay] ?? priceDisplay;
}

/** The short tax note beside an order total. */
export function taxTreatmentLabel(treatment: string): string {
    const labels: Record<string, string> = {
        standard: trans('VAT'),
        reverse_charge: trans('Reverse charge'),
        // A statute reference, the same in every language.
        small_business: '§ 19 UStG',
    };

    return labels[treatment] ?? treatment;
}

/**
 * Why the operator refunded (ADR-0048) — the codes are the server's
 * `RefundReason`, and it rejects anything else, so the dialog offers
 * exactly these.
 */
export function refundReasonLabels(): Record<
    Konventix.Commerce.Enums.RefundReason,
    string
> {
    return {
        'customer-request': trans('The customer asked for it'),
        'event-cancelled': trans('The event was cancelled'),
        'duplicate-payment': trans('Paid twice'),
        goodwill: trans('Goodwill'),
    };
}

export function refundReasonLabel(reason: string): string {
    return (refundReasonLabels() as Record<string, string>)[reason] ?? reason;
}
