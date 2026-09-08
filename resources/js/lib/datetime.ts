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

/**
 * What the viewing person's own settings say about time.
 *
 * `timezone` is `users.timezone` off the shared `auth.user` prop (#243) —
 * null for a guest and for an account that never set one, and the browser's
 * zone answers then. It is module state rather than a parameter on every
 * call because the alternative is threading the same value through some
 * forty call sites, where the one that forgets is a wrong hour on a page
 * nobody re-reads.
 */
export type DateFormat = 'DD.MM.YYYY' | 'MM/DD/YYYY' | 'YYYY-MM-DD';

export type TimeFormat = '24h' | '12h';

type DateTimeSettings = {
    timezone: string | null;
    dateFormat: DateFormat;
    timeFormat: TimeFormat;
};

const settings: DateTimeSettings = {
    timezone: null,
    dateFormat: 'DD.MM.YYYY',
    timeFormat: '24h',
};

/**
 * Applied once per Inertia visit from `app.ts`, out of the page props.
 *
 * Under SSR this module is shared by every request the render process
 * serves, the same way `app.ts`'s document-title suffix is: the settings are
 * assigned before the page component is awaited and read synchronously while
 * it renders, so the window is empty in practice.
 */
export function configureDateTime(next: Partial<DateTimeSettings>): void {
    if (next.timezone !== undefined) {
        settings.timezone = next.timezone;
    }

    if (next.dateFormat !== undefined) {
        settings.dateFormat = next.dateFormat;
    }

    if (next.timeFormat !== undefined) {
        settings.timeFormat = next.timeFormat;
    }
}

/**
 * Renders something under settings other than the ones in force, and puts
 * them back afterwards — the operator settings page previewing a format it
 * has not saved yet, and nothing else. Synchronous by construction: the
 * settings are module state, so a preview that awaited anything would leak
 * its own choice into whatever rendered next.
 */
export function withDateTimeSettings<T>(
    overrides: Partial<DateTimeSettings>,
    render: () => T,
): T {
    const previous = { ...settings };

    configureDateTime(overrides);

    try {
        return render();
    } finally {
        configureDateTime(previous);
    }
}

/**
 * The zone every timestamp on screen is rendered in: the person's own
 * setting when they have one, their browser's when they do not.
 */
export function viewerTimezone(): string {
    return settings.timezone ?? browserTimezone;
}

const formatters = new Map<string, Intl.DateTimeFormat>();

/**
 * The locale whose numbering the operator's chosen pattern belongs to, for
 * the option sets that still go through `Intl` — a `dateStyle: 'long'` or a
 * `timeZoneName`, which cannot be assembled from parts. The three patterns
 * are exactly these three locales' numeric defaults, so a page asking for a
 * worded date still words it the operator's way rather than the browser's.
 */
function locale(): string {
    switch (settings.dateFormat) {
        case 'MM/DD/YYYY':
            return 'en-US';
        case 'YYYY-MM-DD':
            return 'en-CA';
        default:
            return 'de-DE';
    }
}

function formatter(
    options: Intl.DateTimeFormatOptions,
    forLocale: string | undefined = undefined,
): Intl.DateTimeFormat {
    const key = `${forLocale ?? ''}|${JSON.stringify(options)}`;
    let cached = formatters.get(key);

    if (cached === undefined) {
        cached = new Intl.DateTimeFormat(forLocale, options);
        formatters.set(key, cached);
    }

    return cached;
}

/**
 * The viewer's zone folded into an option set, unless the caller named one
 * itself — a page that deliberately renders a fixed zone (a test, a legal
 * text that has to say UTC) keeps it.
 */
function inViewerZone(
    options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormatOptions {
    const zoned =
        options.timeZone === undefined
            ? { ...options, timeZone: viewerTimezone() }
            : options;

    // The operator's clock, unless the caller pinned one itself. `hour12`
    // and `hourCycle` are the same knob to `Intl`, so setting ours over a
    // caller's `hourCycle` would silently override it.
    return zoned.hour12 === undefined && zoned.hourCycle === undefined
        ? { ...zoned, hourCycle: settings.timeFormat === '12h' ? 'h12' : 'h23' }
        : zoned;
}

const pad = (part: number): string => String(part).padStart(2, '0');

/**
 * A date written the operator's way. Assembled from the parts rather than
 * left to a locale, so this and `Operators\OperatorDateTimeFormat` on the
 * server produce the identical string for the identical setting — which is
 * the whole point of the setting being operator-wide.
 */
function writeDate(year: number, month: number, day: number): string {
    switch (settings.dateFormat) {
        case 'MM/DD/YYYY':
            return `${pad(month)}/${pad(day)}/${year}`;
        case 'YYYY-MM-DD':
            return `${year}-${pad(month)}-${pad(day)}`;
        default:
            return `${pad(day)}.${pad(month)}.${year}`;
    }
}

/**
 * A time on the operator's clock. `2:05 PM` rather than `02:05 PM`: a
 * leading zero on an am/pm hour is not how anybody writes one, and PHP's
 * `g:i A` on the server does not write one either.
 */
function writeTime(hour: number, minute: number): string {
    if (settings.timeFormat === '12h') {
        const period = hour < 12 ? 'AM' : 'PM';

        return `${hour % 12 === 0 ? 12 : hour % 12}:${pad(minute)} ${period}`;
    }

    return `${pad(hour)}:${pad(minute)}`;
}

/**
 * A plain `YYYY-MM-DD` read as the day it names rather than as an instant.
 * `null` for anything else, which is the caller's signal to treat the value
 * as a timestamp instead.
 */
function calendarDayParts(
    value: string,
): { year: number; month: number; day: number } | null {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return parts === null
        ? null
        : {
              year: Number(parts[1]),
              month: Number(parts[2]),
              day: Number(parts[3]),
          };
}

/**
 * The date part of a timestamp, in the operator's format and the reader's
 * zone. A calendar date — a repayment deadline, an invoice's due date — is
 * printed as the day it names in every zone there is (#186/#197): read as an
 * instant it would be the day before for a reader west of Greenwich, a day
 * earlier than the sweep that enforces it.
 */
export function formatDate(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const day = calendarDayParts(value);

    if (day !== null) {
        return writeDate(day.year, day.month, day.day);
    }

    const instant = new Date(value);

    if (Number.isNaN(instant.getTime())) {
        return value;
    }

    const clock = wallClockParts(instant, viewerTimezone());

    return writeDate(clock.year, clock.month, clock.day);
}

/**
 * The time part of a timestamp, in the operator's format and the reader's
 * zone. A bare `HH:MM` — what `<input type="time">` speaks — is a wall clock
 * with no date to hang an offset on, and is written out as typed: the
 * operator's 12/24-hour choice is the only thing that changes it.
 */
export function formatTime(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const wallClock = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value);

    if (wallClock !== null) {
        return writeTime(Number(wallClock[1]), Number(wallClock[2]));
    }

    const instant = new Date(value);

    if (Number.isNaN(instant.getTime())) {
        return value;
    }

    const clock = wallClockParts(instant, viewerTimezone());

    return writeTime(clock.hour, clock.minute);
}

/**
 * The wall clock a zone reads for one instant, as the six numbers that make
 * it up. `Intl` is the only zone database the browser exposes, so the
 * conversion goes through a formatter rather than arithmetic on offsets we
 * would have to keep a table of.
 */
function wallClockParts(
    instant: Date,
    zone: string,
): { year: number; month: number; day: number; hour: number; minute: number } {
    const parts = formatter({
        timeZone: zone,
        hourCycle: 'h23',
        numberingSystem: 'latn',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(instant);

    const value = (type: Intl.DateTimeFormatPartTypes): number =>
        Number(parts.find((part) => part.type === type)?.value ?? '0');

    return {
        year: value('year'),
        month: value('month'),
        day: value('day'),
        hour: value('hour'),
        minute: value('minute'),
    };
}

/**
 * How far a zone is from UTC at one instant, in milliseconds — read off the
 * wall clock rather than assumed, so a summer instant and a winter one in
 * the same zone get different answers.
 */
function zoneOffset(instant: Date, zone: string): number {
    const clock = wallClockParts(instant, zone);

    return (
        Date.UTC(
            clock.year,
            clock.month - 1,
            clock.day,
            clock.hour,
            clock.minute,
        ) -
        // The seconds and milliseconds `wallClockParts` drops are the same on
        // both sides of the subtraction for every zone in the database, so
        // rounding the instant down keeps the difference exact.
        Math.floor(instant.getTime() / 60_000) * 60_000
    );
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

    // No option set means the operator's own convention, assembled part by
    // part so the console, the form echo and the invoice agree to the
    // character. A caller that shapes the output itself — a worded date, a
    // zone name — goes through `Intl` with the operator's locale and clock.
    if (Object.keys(options).length === 0) {
        return `${formatDate(value)} ${formatTime(value)}`;
    }

    return formatter(
        inViewerZone({ ...defaults, ...options }),
        locale(),
    ).format(instant);
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
    options?: Intl.DateTimeFormatOptions,
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const day = calendarDayParts(value);

    if (day === null) {
        return formatDateTime(value, options ?? {});
    }

    // Without an option set this is `formatDate()`'s calendar branch, which
    // is the operator's own pattern (#242) and needs no `Intl` at all.
    if (options === undefined) {
        return writeDate(day.year, day.month, day.day);
    }

    const date = new Date(day.year, day.month - 1, day.day);

    // Deliberately *not* `inViewerZone()`: the parts above were read into a
    // local `Date`, and rendering that in another zone would undo exactly
    // the day-shift this function exists to prevent.
    return formatter({ ...options, timeStyle: undefined }, locale()).format(
        date,
    );
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
export function toLocalDateTimeInput(
    value: string | null | undefined,
    zone: string = viewerTimezone(),
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const clock = wallClockParts(date, zone);

    return (
        `${clock.year}-${pad(clock.month)}-${pad(clock.day)}` +
        `T${pad(clock.hour)}:${pad(clock.minute)}`
    );
}

/**
 * The typed wall clock as an absolute instant, which is the only form the
 * server can store without guessing whose clock it was.
 */
export function toInstant(
    value: string,
    zone: string = viewerTimezone(),
): string | null {
    const typed = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(
        value,
    );

    if (typed === null) {
        return null;
    }

    // The digits read as if they were UTC. They are not: they are a wall
    // clock in `zone`, and the instant behind them is that many milliseconds
    // the other way.
    const asUtc = Date.UTC(
        Number(typed[1]),
        Number(typed[2]) - 1,
        Number(typed[3]),
        Number(typed[4]),
        Number(typed[5]),
        Number(typed[6] ?? '0'),
    );

    // Twice, because the offset depends on the instant we are still looking
    // for: the first pass lands within an hour of it, which is close enough
    // for the second to read the offset that actually applies — the reason a
    // date the morning after a DST change comes out right.
    const approximate = asUtc - zoneOffset(new Date(asUtc), zone);

    return new Date(
        asUtc - zoneOffset(new Date(approximate), zone),
    ).toISOString();
}

/**
 * The zone the reader's browser is in, for a hint that has to name it —
 * "Times in your timezone (Europe/Berlin)". Resolved once: it cannot change
 * without a reload.
 */
export const browserTimezone: string =
    Intl.DateTimeFormat().resolvedOptions().timeZone;
