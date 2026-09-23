import { trans } from 'laravel-vue-i18n';

/**
 * The words messaging prints for its enum values (ADR-0068). The raw values
 * (`in_app`, `suppressed`) are wire values, not text. Each label is looked
 * up when it is asked for, never at import time, because the catalog is
 * loaded after the modules are imported.
 *
 * An unknown value falls through unchanged — a case added on the server
 * shows up raw rather than as an empty badge.
 */
export function messageStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        queued: trans('Queued'),
        held: trans('Held'),
        sent: trans('Sent'),
        failed: trans('Failed'),
        suppressed: trans('Suppressed'),
    };

    return labels[status] ?? status;
}

export function channelLabel(channel: string): string {
    const labels: Record<string, string> = {
        mail: trans('Email'),
        in_app: trans('In-app'),
        push: trans('Push'),
    };

    return labels[channel] ?? channel;
}
