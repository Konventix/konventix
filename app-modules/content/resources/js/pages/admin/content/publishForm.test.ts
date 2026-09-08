import { describe, expect, it } from 'vitest';
import { publishedAtInstant } from './publishForm';

/**
 * #246: the "Publish at" field is a `datetime-local`, so it hands back a bare
 * wall clock with no zone. The server read it in `config('app.timezone')`
 * (UTC), and an editor in Berlin who typed 22:00 scheduled the entry for
 * midnight of the next day.
 *
 * The assertions hold in whatever zone the test host runs in: what they pin
 * is that the typed wall clock and the sent instant describe the same moment.
 */
describe('publishedAtInstant', () => {
    it('resolves the typed wall clock against the editor’s own zone', () => {
        expect(publishedAtInstant('2026-09-08T22:00')).toBe(
            new Date(2026, 8, 8, 22, 0).toISOString(),
        );
    });

    it('sends nothing for an empty field, which the endpoint reads as now', () => {
        expect(publishedAtInstant('')).toBeNull();
    });

    it('sends nothing for a value that is not a date', () => {
        expect(publishedAtInstant('whenever')).toBeNull();
    });
});
