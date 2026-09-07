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
