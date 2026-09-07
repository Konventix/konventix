import { describe, expect, it } from 'vitest';
import { formatDateTime } from './datetime';

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
