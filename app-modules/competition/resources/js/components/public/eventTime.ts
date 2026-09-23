import { formatDateTime } from '@/lib/datetime';

/**
 * A match time on the event's wall clock (ADR-0027, competition design
 * §10a "Time"). The server stores the instant; the hall reads it in the
 * event's zone, whoever is looking — a guest whose profile says New York is
 * still standing in Berlin. Without a zone (the projection has not heard of
 * the event yet) it falls back to the reader's own, which is what
 * `formatDateTime()` does on its own.
 *
 * `options` shapes the output like `formatDateTime()`'s; the zone always
 * wins over one passed in, because naming the event's zone is the point.
 */
export function formatEventDateTime(
    value: string | null | undefined,
    timezone: string | null,
    options: Intl.DateTimeFormatOptions = {},
): string {
    return timezone === null
        ? formatDateTime(value, options)
        : formatDateTime(value, { ...options, timeZone: timezone });
}
