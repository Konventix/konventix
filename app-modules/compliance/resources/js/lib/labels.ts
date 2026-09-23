import { trans } from 'laravel-vue-i18n';

/**
 * The words compliance prints for its enum values (ADR-0068). The raw values
 * (`in_progress`, `consent_withdrawal`) are wire values, not text: a page
 * that printed them showed English to every locale. Each label is looked up
 * when it is asked for, never at import time, because the catalog is loaded
 * after the modules are imported.
 *
 * An unknown value falls through unchanged — a state added on the server
 * shows up raw rather than as an empty badge.
 */
export function requestStateLabel(state: string): string {
    const labels: Record<string, string> = {
        received: trans('Received'),
        in_progress: trans('In progress'),
        fulfilled: trans('Fulfilled'),
        refused: trans('Refused'),
    };

    return labels[state] ?? state;
}

/**
 * The same states as the data subject reads them on their own privacy page:
 * "Done" rather than the console's "Fulfilled".
 */
export function subjectRequestStateLabel(state: string): string {
    const labels: Record<string, string> = {
        received: trans('Received'),
        in_progress: trans('In progress'),
        fulfilled: trans('Done'),
        refused: trans('Refused'),
    };

    return labels[state] ?? state;
}

export function requestTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        export: trans('GDPR export'),
        deletion: trans('Deletion'),
        rectification: trans('Rectification'),
        consent_withdrawal: trans('Consent withdrawal'),
    };

    return labels[type] ?? type;
}

export function deletionModeLabel(mode: string): string {
    const labels: Record<string, string> = {
        anonymize: trans('Anonymize'),
        erase: trans('Erase'),
    };

    return labels[mode] ?? mode;
}

export function moduleResponseStateLabel(state: string): string {
    const labels: Record<string, string> = {
        pending: trans('Pending'),
        completed: trans('Completed'),
        failed: trans('Failed'),
    };

    return labels[state] ?? state;
}

export function policyVersionStateLabel(state: string): string {
    const labels: Record<string, string> = {
        draft: trans('Draft'),
        published: trans('Published'),
        superseded: trans('Superseded'),
    };

    return labels[state] ?? state;
}

export function retentionClockStartLabel(clockStart: string): string {
    const labels: Record<string, string> = {
        record_age: trans('Age of each record'),
        event_concluded: trans('End of the event'),
    };

    return labels[clockStart] ?? clockStart;
}

/**
 * Where an agreement was captured. `capture_context` is a free string each
 * capturing module writes (`checkout`, `registration`, `consent_gate`, …), so
 * only the known ones get words and anything else is shown as stored.
 */
export function captureContextLabel(context: string): string {
    const labels: Record<string, string> = {
        checkout: trans('at checkout'),
        registration: trans('at registration'),
        registration_form: trans('at registration'),
        consent_gate: trans('on the consent page'),
        consent_form: trans('on the consent page'),
    };

    return labels[context] ?? context;
}
