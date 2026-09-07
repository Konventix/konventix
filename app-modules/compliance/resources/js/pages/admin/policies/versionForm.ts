/**
 * The policy version editor's date handling, kept out of the page so it can
 * be tested as what it is: three pure functions.
 *
 * A `datetime-local` control speaks wall clock and nothing else — it hands
 * back `YYYY-MM-DDTHH:MM` with no zone attached. The server used to read that
 * string in `config('app.timezone')` (UTC), so an operator in Berlin typing
 * "now" got an `effective_at` one or two hours in the future, and
 * `/policies/{slug}` answered 404 until it arrived. The page therefore
 * resolves the wall clock against the operator's own zone before it sends,
 * and converts back on the way in.
 */

/**
 * The stored instant as the reader's own wall clock, in the format
 * `datetime-local` accepts. An unparsable or absent value leaves the field
 * empty rather than showing `Invalid Date`.
 */
export function toLocalInput(value: string | null): string {
    if (value === null || value === '') {
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

// Spelled out component by component rather than with `dateStyle`/
// `timeStyle`: those two are mutually exclusive with `timeZoneName`, and the
// zone is the whole point of writing the date out here.
const instantFormat = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
});

/**
 * An instant written out for a human, in the reader's zone and saying which
 * zone that is — a list of legal effective dates that does not name its zone
 * reads as one hour to Berlin and another to Lisbon, with nothing on screen
 * to tell them apart.
 */
export function formatInstant(value: string | null): string {
    if (value === null || value === '') {
        return 'no date';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'no date'
        : instantFormat.format(date);
}
