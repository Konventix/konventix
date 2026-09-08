import { describe, expect, it } from 'vitest';
import { toInstant, toLocalInput, versionFormValues } from './versionForm';

/**
 * The `datetime-local` round trip of the policy version editor. Pure
 * functions, tested here rather than through the rendered page for the reason
 * `resources/js/entrance/lib/evaluate.test.ts` gives: the rule is the
 * function, and a field that showed the wrong hour is a bug in the rule.
 *
 * What they exist for: the control hands back a bare wall clock with no zone,
 * the server read it in `config('app.timezone')` (UTC), and every version an
 * operator in Berlin meant as "from now on" was dated one offset into the
 * future — `Policy::currentVersion()` filters on `effective_at <= now()`, so
 * `/policies/{slug}` answered 404 for exactly that long.
 *
 * The assertions are written to hold in whatever zone the test host runs in:
 * what they pin is that a wall clock and its instant describe the same moment.
 */
describe('toInstant', () => {
    it('resolves the typed wall clock against the operator’s own zone', () => {
        expect(toInstant('2026-09-07T22:44')).toBe(
            new Date(2026, 8, 7, 22, 44).toISOString(),
        );
    });

    it('has no instant for an empty field', () => {
        expect(toInstant('')).toBeNull();
    });
});

describe('toLocalInput', () => {
    it('puts a stored instant back as the wall clock that produced it', () => {
        expect(toLocalInput(toInstant('2026-09-07T22:44'))).toBe(
            '2026-09-07T22:44',
        );
    });

    it('reads the offset the server sends rather than the digits', () => {
        // 20:44 UTC is 22:44 in Berlin. Whichever zone the reader is in, the
        // field has to show *their* wall clock for that one instant.
        const instant = new Date('2026-09-07T20:44:00Z');
        const field = toLocalInput('2026-09-07T20:44:00+00:00');

        expect(field).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        expect(new Date(field).getTime()).toBe(instant.getTime());
    });

    it('leaves the field empty for a version that has no date yet', () => {
        expect(toLocalInput(null)).toBe('');
    });
});

/**
 * Inertia keeps the same page component across a redirect that renders the
 * same page, so `setup()` — and with it `useForm`'s initial values — runs
 * once for the whole session. After "New draft version" redirected to the
 * editor of the *new* draft, the form still held the previously opened
 * version's label, and saving wrote it onto the new row: a unique-index
 * violation on `policy_versions(policy_id, version)` and a 500, with the
 * draft row already standing.
 *
 * The page re-seeds the form from the props on every version change; this is
 * the function it re-seeds from.
 */
describe('versionFormValues', () => {
    const version = {
        version: '2026-002',
        content: 'Die neuen Bedingungen.',
        requiresConsent: false,
        lawfulBasis: 'contract',
        effectiveAt: null,
    };

    it('takes every field from the version it is given', () => {
        expect(versionFormValues(version)).toEqual({
            version: '2026-002',
            content: 'Die neuen Bedingungen.',
            requiresConsent: false,
            lawfulBasis: 'contract',
            effectiveAt: '',
        });
    });

    it('carries nothing over from the version opened before it', () => {
        const previous = versionFormValues({
            ...version,
            version: '2026-001',
            content: 'Die alten Bedingungen.',
            effectiveAt: '2026-09-07T20:44:00+00:00',
        });

        expect(versionFormValues(version).version).not.toBe(previous.version);
        expect(versionFormValues(version).content).not.toBe(previous.content);
        expect(versionFormValues(version).effectiveAt).toBe('');
    });

    it('opens an empty form for a policy with no versions yet', () => {
        expect(versionFormValues(null)).toEqual({
            version: '',
            content: '',
            requiresConsent: true,
            lawfulBasis: 'consent',
            effectiveAt: '',
        });
    });
});
