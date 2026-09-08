import { describe, expect, it } from 'vitest';
import {
    formatDateTime,
    toInstant,
    toLocalDateTimeInput,
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
