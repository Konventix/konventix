import { trans } from 'laravel-vue-i18n';

/**
 * The words admission prints for its wire values (ADR-0068). The raw values
 * (`issued`, `unconfirmed`, `console`) are not text: a page that printed them
 * showed English to every locale. Each label is looked up when it is asked
 * for, never at import time, because the catalog is loaded after the modules
 * are imported.
 *
 * An unknown value falls through unchanged — a state added on the server
 * shows up raw rather than as an empty badge.
 */
export function admissionStateLabel(state: string): string {
    const labels: Record<string, string> = {
        issued: trans('Issued'),
        assigned: trans('Assigned'),
        revoked: trans('Revoked'),
        expired: trans('Expired'),
    };

    return labels[state] ?? state;
}

/**
 * The registration's status as Admission knows it. `unconfirmed` comes from
 * a backfill whose emitter predates the status field and only said "not
 * confirmed" — the label says exactly that.
 */
export function registrationStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        confirmed: trans('Confirmed'),
        unconfirmed: trans('Not confirmed'),
        submitted: trans('Submitted'),
        approved: trans('Approved'),
        rejected: trans('Rejected'),
        cancelled: trans('Cancelled'),
    };

    return labels[status] ?? status;
}

/** Where a check-in was recorded: the door device or the console desk. */
export function checkInSourceLabel(source: string): string {
    const labels: Record<string, string> = {
        door: trans('Door'),
        console: trans('Console'),
    };

    return labels[source] ?? source;
}

/** A door check's answer. */
export function doorCheckOutcomeLabel(outcome: string): string {
    const labels: Record<string, string> = {
        confirmed: trans('Confirmed'),
        refused: trans('Refused'),
    };

    return labels[outcome] ?? outcome;
}
