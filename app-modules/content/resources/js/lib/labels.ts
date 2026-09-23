import { trans } from 'laravel-vue-i18n';

/**
 * The words content prints for its enum values (ADR-0068). The raw values
 * (`logged-in`, `unpublished`) are wire values, not text. Each label is
 * looked up when it is asked for, never at import time, because the catalog
 * is loaded after the modules are imported. An unknown value falls through
 * unchanged.
 */
export function contentTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        news: trans('News'),
        page: trans('Static page'),
        announcement: trans('Announcement'),
    };

    return labels[type] ?? type;
}

export function publicationStateLabel(state: string): string {
    const labels: Record<string, string> = {
        draft: trans('Draft'),
        scheduled: trans('Scheduled for publication'),
        published: trans('Published'),
        unpublished: trans('Unpublished'),
        archived: trans('Archived'),
    };

    return labels[state] ?? state;
}

export function commentStateLabel(state: string): string {
    const labels: Record<string, string> = {
        pending: trans('Pending'),
        approved: trans('Approved'),
        rejected: trans('Rejected'),
        hidden: trans('Hidden'),
    };

    return labels[state] ?? state;
}

export function audienceLabel(audience: string): string {
    const labels: Record<string, string> = {
        everyone: trans('Everyone'),
        'logged-in': trans('Logged-in users'),
    };

    return labels[audience] ?? audience;
}

export function urgencyLabel(urgency: string): string {
    const labels: Record<string, string> = {
        normal: trans('Normal'),
        urgent: trans('Urgent'),
    };

    return labels[urgency] ?? urgency;
}
