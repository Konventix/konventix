import { trans } from 'laravel-vue-i18n';

/**
 * The words the programme editor prints for an item's state (ADR-0068). The
 * raw enum value is a wire value, not text. Looked up when asked for, never
 * at import time, because the catalog is loaded after the modules are
 * imported. An unknown value falls through unchanged.
 */
export function itemStateLabel(state: string): string {
    const labels: Record<string, string> = {
        draft: trans('Draft'),
        published: trans('Published'),
        cancelled: trans('Called off'),
    };

    return labels[state] ?? state;
}
