import { trans } from 'laravel-vue-i18n';

/**
 * The words the event module prints for its enum values (ADR-0068). The raw
 * values (`draft`, `once_per_day`) are wire values, not text. Each label is
 * looked up when it is asked for, never at import time, because the catalog
 * is loaded after the modules are imported.
 *
 * An unknown value falls through unchanged — a state added on the server
 * shows up raw rather than as an empty badge.
 */
export function eventStateLabel(state: string): string {
    const labels: Record<string, string> = {
        draft: trans('Draft'),
        announced: trans('Announced'),
        published: trans('Published'),
        running: trans('Running'),
        concluded: trans('Concluded'),
        archived: trans('Archived'),
        // Not commerce's "Cancelled" (an order is storniert, an event is
        // abgesagt): one catalog serves every module, so the word differs.
        cancelled: trans('Called off'),
    };

    return labels[state] ?? state;
}

/**
 * The lifecycle transitions the dashboard offers as buttons — the server
 * sends exactly the ones legal from the current state.
 */
export function eventTransitionLabel(transition: string): string {
    const labels: Record<string, string> = {
        announce: trans('Announce'),
        publish: trans('Publish'),
        unpublish: trans('Unpublish'),
        start: trans('Start'),
        conclude: trans('Conclude'),
        cancel: trans('Cancel event'),
        archive: trans('Archive'),
    };

    return labels[transition] ?? transition;
}
