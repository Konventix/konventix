import { afterEach, describe, expect, it } from 'vitest';
import {
    browserTimezone,
    configureDateTime,
    formatCalendarDate,
    formatDate,
    formatDateTime,
    formatTime,
    toInstant,
    toLocalDateTimeInput,
    viewerTimezone,
    withZoneName,
} from './datetime';

describe('formatDateTime', () => {
    it('shows a UTC instant in the reader’s own zone', () => {
        // The dev finding behind this helper: a registration submitted at
        // 21:55:02 UTC is 23:55 for a reader in Europe/Berlin, and used to be
        // printed as 21:55.
        expect(
            formatDateTime('2026-09-07T21:55:02+00:00', {
                dateStyle: undefined,
                timeStyle: undefined,
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
                timeZone: 'Europe/Berlin',
            }),
        ).toBe('23:55');
    });

    it('reads the offset that comes with the instant rather than assuming UTC', () => {
        expect(
            formatDateTime('2026-09-07T23:55:02+02:00', {
                dateStyle: undefined,
                timeStyle: undefined,
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
                timeZone: 'UTC',
            }),
        ).toBe('21:55');
    });

    it('renders an absent timestamp as nothing at all', () => {
        expect(formatDateTime(null)).toBe('');
        expect(formatDateTime(undefined)).toBe('');
        expect(formatDateTime('')).toBe('');
    });

    it('passes an unparseable value through instead of printing "Invalid Date"', () => {
        expect(formatDateTime('not a date')).toBe('not a date');
    });
});

describe('a legal effective date', () => {
    it('lands on the day the operator meant, for a reader in that zone', () => {
        // B5/#203: a Berlin operator dating a version 2026-09-08T00:30
        // stores 2026-09-07T22:30Z. Sent as a bare `toDateString()` the
        // public page printed "7 September" to everybody; sent as the
        // instant, a Berlin reader sees the 8th.
        const berlin = formatDateTime('2026-09-07T22:30:00+00:00', {
            dateStyle: 'long',
            timeStyle: undefined,
            timeZone: 'Europe/Berlin',
        });

        expect(berlin).toContain('8');
        expect(berlin).not.toBe(
            formatDateTime('2026-09-07T22:30:00+00:00', {
                dateStyle: 'long',
                timeStyle: undefined,
                timeZone: 'UTC',
            }),
        );
    });
});

describe('withZoneName', () => {
    it('names the zone the instant is rendered in', () => {
        // `dateStyle`/`timeStyle` are mutually exclusive with `timeZoneName`,
        // so the option set spells the components out — a list of legal
        // effective dates that does not say which zone it is in reads as one
        // hour in Berlin and another in Lisbon.
        const rendered = formatDateTime('2026-09-07T20:44:00+00:00', {
            ...withZoneName,
            timeZone: 'UTC',
        });

        expect(rendered).toContain('2026');
        expect(rendered).toContain('UTC');
    });

    it('keeps the one empty-value policy: nothing, not a word', () => {
        // The policy editor used to answer 'no date' here while every other
        // timestamp on the console answered ''. One helper, one policy — the
        // page supplies the word where a word belongs.
        expect(formatDateTime(null, withZoneName)).toBe('');
        expect(formatDateTime('', withZoneName)).toBe('');
    });
});

/**
 * The `datetime-local` round trip (#237, the rule #202 established for a
 * policy version's effective date). What it exists for: the control hands
 * back a bare wall clock with no zone, the server read it in
 * `config('app.timezone')` (UTC), and a registration type an operator in
 * Berlin meant to go on sale at 10:17 went on sale at 12:17 on their clock.
 *
 * The assertions hold in whatever zone the test host runs in: what they pin
 * is that a wall clock and its instant describe the same moment.
 */
describe('toInstant', () => {
    it('resolves the typed wall clock against the reader’s own zone', () => {
        expect(toInstant('2026-09-08T10:17')).toBe(
            new Date(2026, 8, 8, 10, 17).toISOString(),
        );
    });

    it('has no instant for an empty field', () => {
        expect(toInstant('')).toBeNull();
    });

    it('has no instant for a value that is not a date', () => {
        expect(toInstant('whenever')).toBeNull();
    });
});

describe('toLocalDateTimeInput', () => {
    // A tautology of the two helpers on its own — it pins that they are each
    // other's inverse, nothing more. The offset case below is the one
    // carrying the weight (review nit 10).
    it('puts a stored instant back as the wall clock that produced it', () => {
        expect(toLocalDateTimeInput(toInstant('2026-09-08T10:17'))).toBe(
            '2026-09-08T10:17',
        );
    });

    it('reads the offset the server sends rather than the digits', () => {
        // 08:17 UTC is 10:17 in Berlin. Whichever zone the reader is in, the
        // field has to show *their* wall clock for that one instant — the
        // digits alone are what #237 shipped and what was wrong about it.
        const instant = new Date('2026-09-08T08:17:00Z');
        const field = toLocalDateTimeInput('2026-09-08T08:17:00+00:00');

        expect(field).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        expect(new Date(field).getTime()).toBe(instant.getTime());
    });

    it('leaves the field empty for a bound that is not set', () => {
        expect(toLocalDateTimeInput(null)).toBe('');
        expect(toLocalDateTimeInput(undefined)).toBe('');
        expect(toLocalDateTimeInput('')).toBe('');
        expect(toLocalDateTimeInput('not a date')).toBe('');
    });
});

/**
 * The reader's own zone comes from their profile, not from their laptop
 * (#243). `configureDateTime()` is called once per Inertia visit from
 * `app.ts` with the shared `auth.user.timezone` prop; every helper in this
 * file then answers in that zone without a single page passing it along.
 */
describe('the viewer’s configured timezone', () => {
    afterEach(() => {
        configureDateTime({ timezone: null });
    });

    it('is the browser’s until a profile setting says otherwise', () => {
        expect(viewerTimezone()).toBe(browserTimezone);
    });

    it('renders an instant in the profile zone rather than the browser’s', () => {
        configureDateTime({ timezone: 'America/New_York' });

        // 2026-09-08T12:00Z is 08:00 in New York, whatever the test host's
        // own zone happens to be.
        expect(
            formatDateTime('2026-09-08T12:00:00+00:00', {
                dateStyle: undefined,
                timeStyle: undefined,
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }),
        ).toBe('08:00');
    });

    it('lets a caller that names a zone keep it', () => {
        configureDateTime({ timezone: 'America/New_York' });

        expect(
            formatDateTime('2026-09-08T12:00:00+00:00', {
                dateStyle: undefined,
                timeStyle: undefined,
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
                timeZone: 'UTC',
            }),
        ).toBe('12:00');
    });

    it('fills a datetime-local field with the profile zone’s wall clock', () => {
        configureDateTime({ timezone: 'America/New_York' });

        expect(toLocalDateTimeInput('2026-09-08T12:00:00+00:00')).toBe(
            '2026-09-08T08:00',
        );
    });

    it('reads a typed wall clock back as an instant in the profile zone', () => {
        configureDateTime({ timezone: 'America/New_York' });

        expect(toInstant('2026-09-08T08:00')).toBe('2026-09-08T12:00:00.000Z');
    });

    it('crosses a DST boundary without drifting an hour', () => {
        // New York falls back on 2026-11-01. The morning after it is on
        // EST (-05:00), and an offset looked up against the typed digits
        // read as UTC — a full day and five hours away — would still be on
        // EDT and put the instant an hour out.
        configureDateTime({ timezone: 'America/New_York' });

        expect(toInstant('2026-11-02T01:30')).toBe('2026-11-02T06:30:00.000Z');
    });
});

/**
 * The operator's own conventions (#242): three ways to write a date, two
 * clocks, and one instant that has to come out the same on a console table,
 * in a form's echo line and on the invoice the server renders.
 *
 * Assembled from the parts rather than left to a locale, so the browser and
 * `Operators\OperatorDateTimeFormat` on the server produce the identical
 * string for the identical setting.
 */
describe('the operator’s date and time format', () => {
    afterEach(() => {
        configureDateTime({
            timezone: null,
            dateFormat: 'DD.MM.YYYY',
            timeFormat: '24h',
        });
    });

    const instant = '2026-09-08T14:05:00+00:00';

    it.each([
        ['DD.MM.YYYY', '08.09.2026'],
        ['MM/DD/YYYY', '09/08/2026'],
        ['YYYY-MM-DD', '2026-09-08'],
    ] as const)('writes a date as %s', (dateFormat, expected) => {
        configureDateTime({ dateFormat, timezone: 'UTC' });

        expect(formatDate(instant)).toBe(expected);
    });

    it.each([
        ['24h', '14:05'],
        ['12h', '2:05 PM'],
    ] as const)('writes a time on the %s clock', (timeFormat, expected) => {
        configureDateTime({ timeFormat, timezone: 'UTC' });

        expect(formatTime(instant)).toBe(expected);
    });

    it.each([
        ['DD.MM.YYYY', '24h', '08.09.2026 14:05'],
        ['DD.MM.YYYY', '12h', '08.09.2026 2:05 PM'],
        ['MM/DD/YYYY', '24h', '09/08/2026 14:05'],
        ['MM/DD/YYYY', '12h', '09/08/2026 2:05 PM'],
        ['YYYY-MM-DD', '24h', '2026-09-08 14:05'],
        ['YYYY-MM-DD', '12h', '2026-09-08 2:05 PM'],
    ] as const)(
        'writes a timestamp as %s on the %s clock',
        (dateFormat, timeFormat, expected) => {
            configureDateTime({ dateFormat, timeFormat, timezone: 'UTC' });

            expect(formatDateTime(instant)).toBe(expected);
        },
    );

    it('puts midnight and noon on the right side of the 12-hour clock', () => {
        configureDateTime({ timeFormat: '12h', timezone: 'UTC' });

        expect(formatTime('2026-09-08T00:30:00+00:00')).toBe('12:30 AM');
        expect(formatTime('2026-09-08T12:30:00+00:00')).toBe('12:30 PM');
    });

    it('reads the date in the viewer’s zone, not the instant’s', () => {
        configureDateTime({
            dateFormat: 'DD.MM.YYYY',
            timezone: 'Europe/Berlin',
        });

        // 22:30 UTC is half past midnight on the 9th in Berlin.
        expect(formatDate('2026-09-08T22:30:00+00:00')).toBe('09.09.2026');
    });

    it('leaves a calendar date on the day it names, in every zone', () => {
        // A repayment deadline is a day, not an instant (#186/#197): read as
        // UTC midnight it would be the day before for a reader west of
        // Greenwich, which is a day earlier than the sweep that enforces it.
        configureDateTime({
            dateFormat: 'MM/DD/YYYY',
            timezone: 'Pacific/Honolulu',
        });

        expect(formatDate('2026-09-14')).toBe('09/14/2026');
        expect(formatCalendarDate('2026-09-14')).toBe('09/14/2026');
    });

    it('still renders nothing for an absent value and passes junk through', () => {
        expect(formatDate(null)).toBe('');
        expect(formatTime(undefined)).toBe('');
        expect(formatDate('not a date')).toBe('not a date');
    });
});

describe('a bare time of day', () => {
    afterEach(() => {
        configureDateTime({ timezone: null, timeFormat: '24h' });
    });

    it('is written out as typed, on the operator’s clock', () => {
        // What `<input type="time">` speaks. There is no date on it to hang an
        // offset on, so converting it to the reader's zone would move a
        // doors-open time by however far the reader happens to be.
        configureDateTime({ timezone: 'America/New_York', timeFormat: '24h' });
        expect(formatTime('18:30')).toBe('18:30');

        configureDateTime({ timeFormat: '12h' });
        expect(formatTime('18:30')).toBe('6:30 PM');
        expect(formatTime('00:05')).toBe('12:05 AM');
    });
});
