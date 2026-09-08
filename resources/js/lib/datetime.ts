/**
 * One shared way to put a timestamp on screen.
 *
 * System timestamps — when a registration was submitted, when an order was
 * placed — are instants and reach the browser as ISO 8601 with an offset
 * (ADR-0027 keeps event times, and only those, as the organizer's wall clock
 * plus a zone label). The zone such an instant should be *shown* in is the
 * reader's, and the browser is the only party that knows it, so the
 * conversion belongs here rather than in a `format()` call on the server —
 * which is what used to print a UTC 21:55 to a reader in Berlin whose clock
 * said 23:55.
 *
 * Formatters are cached per option set: building an `Intl.DateTimeFormat` is
 * the expensive part, and a list re-renders often.
 */
const defaults: Intl.DateTimeFormatOptions = {
    dateStyle: 'medium',
    timeStyle: 'short',
};

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatter(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = JSON.stringify(options);
    let cached = formatters.get(key);

    if (cached === undefined) {
        // `undefined` as the locale: the reader's, not the brand's — the same
        // choice Commerce's order list already made.
        cached = new Intl.DateTimeFormat(undefined, options);
        formatters.set(key, cached);
    }

    return cached;
}

/**
 * An ISO 8601 instant as the reader's own date and time. An absent value
 * renders as nothing, and a value that is not a date at all is passed
 * through unchanged rather than becoming "Invalid Date" on a public page.
 */
export function formatDateTime(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {},
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const instant = new Date(value);

    if (Number.isNaN(instant.getTime())) {
        return value;
    }

    return formatter({ ...defaults, ...options }).format(instant);
}

/**
 * A calendar date — `2026-09-14` — as the reader's own wording, and
 * deliberately *not* through `formatDateTime()` (#186/#197 review finding 5).
 *
 * A repayment deadline, an invoice's due date or an issue date is a day, not
 * an instant: `repay_due_on` is written as `startOfDay()->addDays(n)` and
 * stored in a `date` column, so there is no time in it to convert. Handing
 * `2026-09-14` to `new Date()` parses it as UTC midnight, and a reader west
 * of Greenwich would then be told the deadline is the 13th — a day earlier
 * than the sweep that enforces it.
 *
 * So the parts are read off the string and handed to a *local* `Date`, which
 * has the same calendar date in every zone there is. A value that is not a
 * plain date falls through to `formatDateTime()`, which handles instants and
 * passes anything unparseable through unchanged.
 */
export function formatCalendarDate(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'long' },
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (parts === null) {
        return formatDateTime(value, options);
    }

    const date = new Date(
        Number(parts[1]),
        Number(parts[2]) - 1,
        Number(parts[3]),
    );

    return formatter({ ...options, timeStyle: undefined }).format(date);
}

/**
 * The option set for a timestamp that has to say which zone it is in — a
 * legal effective date, read by an operator in Berlin and a colleague in
 * Lisbon, is one instant and two wall clocks, with nothing on screen to
 * tell them apart.
 *
 * Spelled out component by component rather than with `dateStyle`/
 * `timeStyle`: those two are mutually exclusive with `timeZoneName`, and
 * the zone is the whole point. The explicit `undefined`s override the
 * defaults `formatDateTime()` merges in.
 */
export const withZoneName: Intl.DateTimeFormatOptions = {
    dateStyle: undefined,
    timeStyle: undefined,
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
};

/**
 * The `datetime-local` round trip.
 *
 * A `datetime-local` control speaks wall clock and nothing else: it hands
 * back `YYYY-MM-DDTHH:MM` with no zone attached. A server that reads that
 * string in `config('app.timezone')` is guessing whose clock it was, and on
 * a UTC deployment it guesses wrong for every operator who is not on it —
 * #202 for a policy version's effective date, #237 for a registration type's
 * sales window, where an operator in Berlin typed 10:17 and the landing page
 * announced 12:17. The browser is the only party that knows the zone, so the
 * resolution happens here, on the way out and on the way back.
 *
 * These two belong beside `formatDateTime()` for the same reason it exists:
 * an instant is stored, a wall clock is shown, and the conversion between
 * them is the browser's job — one implementation of it, not one per form.
 */

/**
 * The stored instant as the reader's own wall clock, in the format
 * `datetime-local` accepts. An unparsable or absent value leaves the field
 * empty rather than showing `Invalid Date`.
 */
export function toLocalDateTimeInput(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (part: number): string => String(part).padStart(2, '0');

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}

/**
 * The typed wall clock as an absolute instant, which is the only form the
 * server can store without guessing whose clock it was.
 */
export function toInstant(value: string): string | null {
    if (value === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * The zone the reader's browser is in, for a hint that has to name it —
 * "Times in your timezone (Europe/Berlin)". Resolved once: it cannot change
 * without a reload.
 */
export const browserTimezone: string =
    Intl.DateTimeFormat().resolvedOptions().timeZone;
