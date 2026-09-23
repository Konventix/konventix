import { afterEach, describe, expect, it } from 'vitest';
import { configureDateTime } from '@/lib/datetime';
import { formatEventDateTime } from './eventTime';

/** Hours and minutes only, so the assertion is about the clock and nothing else. */
const clock: Intl.DateTimeFormatOptions = {
    dateStyle: undefined,
    timeStyle: undefined,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
};

describe('formatEventDateTime', () => {
    afterEach(() => {
        configureDateTime({ timezone: null });
    });

    it('shows a match on the event’s wall clock, not the reader’s', () => {
        // A reader whose profile says New York, at an event in Berlin.
        configureDateTime({ timezone: 'America/New_York' });

        // 2026-11-21 is after the end of summer time (last Sunday of
        // October, 2026-10-25), so Berlin is on CET, UTC+1: 18:00 UTC is
        // 19:00 in the hall.
        expect(
            formatEventDateTime(
                '2026-11-21T18:00:00+00:00',
                'Europe/Berlin',
                clock,
            ),
        ).toBe('19:00');

        // 2026-07-10 is inside summer time (2026-03-29 to 2026-10-25), CEST,
        // UTC+2: the same 18:00 UTC is 20:00.
        expect(
            formatEventDateTime(
                '2026-07-10T18:00:00+00:00',
                'Europe/Berlin',
                clock,
            ),
        ).toBe('20:00');
    });

    it('falls back to the reader’s zone when the event’s is not known', () => {
        configureDateTime({ timezone: 'America/New_York' });

        // 2026-11-21 is after New York's change (2026-11-01), EST, UTC−5:
        // 18:00 UTC is 13:00.
        expect(
            formatEventDateTime('2026-11-21T18:00:00+00:00', null, clock),
        ).toBe('13:00');
    });
});
