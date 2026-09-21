import { describe, expect, it } from 'vitest';
import {
    acceptCorrelationId,
    describeClientError,
    disclosedFields,
    emptyContextState,
    previousVisit,
    rememberVisit,
    reportableUrl,
    snapshotContext,
    VISIT_BUFFER_SIZE,
} from './supportContext';
import type { SupportVisit } from './supportContext';

function visit(n: number): SupportVisit {
    return {
        url: `https://sxlan.test/page-${n}`,
        correlationId: `01K5MZ6V4N8YQ2W3J7R9T1X0A${n}`,
        at: '2026-09-21T10:00:00.000Z',
    };
}

describe('the visit ring buffer', () => {
    it('keeps the newest five and drops the sixth-oldest', () => {
        let visits: SupportVisit[] = [];

        for (let n = 1; n <= 7; n += 1) {
            visits = rememberVisit(visits, visit(n));
        }

        expect(visits).toHaveLength(VISIT_BUFFER_SIZE);
        expect(visits.map((entry) => entry.url)).toEqual([
            'https://sxlan.test/page-3',
            'https://sxlan.test/page-4',
            'https://sxlan.test/page-5',
            'https://sxlan.test/page-6',
            'https://sxlan.test/page-7',
        ]);
    });

    it('never mutates the array it was given, so a reactive holder notices', () => {
        const before: SupportVisit[] = [visit(1)];
        const after = rememberVisit(before, visit(2));

        expect(before).toHaveLength(1);
        expect(after).toHaveLength(2);
    });

    /**
     * The point of the buffer (design §6.3): people report the page they
     * are looking at, and the thing that broke happened on the one before.
     */
    it('reads the previous page as the entry before the current one', () => {
        expect(previousVisit([])).toBeNull();
        expect(previousVisit([visit(1)])).toBeNull();
        expect(previousVisit([visit(1), visit(2)])?.url).toBe(
            'https://sxlan.test/page-1',
        );
    });
});

describe('what the client is allowed to claim', () => {
    it('keeps an id that looks like a correlation id and drops everything else', () => {
        expect(acceptCorrelationId('01K5MZ6V4N8YQ2W3J7R9T1X0AB')).toBe(
            '01K5MZ6V4N8YQ2W3J7R9T1X0AB',
        );
        expect(acceptCorrelationId('a'.repeat(32))).toBe('a'.repeat(32));

        // Too short, too long, wrong alphabet, not a string at all.
        expect(acceptCorrelationId('short')).toBeNull();
        expect(acceptCorrelationId('a'.repeat(65))).toBeNull();
        expect(acceptCorrelationId('has spaces in it')).toBeNull();
        expect(acceptCorrelationId(42)).toBeNull();
        expect(acceptCorrelationId(null)).toBeNull();
    });

    it('keeps the query and drops the fragment of a reported URL', () => {
        expect(reportableUrl('https://sxlan.test/pay?order=7#step-2')).toBe(
            'https://sxlan.test/pay?order=7',
        );
        expect(reportableUrl('')).toBeNull();
    });

    it('bounds a captured client error and keeps message and stack together', () => {
        expect(describeClientError('Boom', 'at foo()')).toBe('Boom\nat foo()');
        expect(describeClientError(undefined, undefined)).toBeNull();
        expect(describeClientError('x'.repeat(5000))?.length).toBe(2000);
    });
});

describe('the snapshot the dialog posts', () => {
    it('derives the previous page from the buffer and validates both ids', () => {
        const state = emptyContextState();
        state.visits = [visit(1), { ...visit(2), correlationId: 'nope' }];

        const snapshot = snapshotContext(state, {
            pageCorrelationId: '01K5MZ6V4N8YQ2W3J7R9T1X0AB',
            href: 'https://sxlan.test/pay#top',
            viewport: { width: 1280, height: 800, dpr: 2 },
            now: '2026-09-21T10:05:00.000Z',
        });

        expect(snapshot.url).toBe('https://sxlan.test/pay');
        expect(snapshot.pageCorrelationId).toBe('01K5MZ6V4N8YQ2W3J7R9T1X0AB');
        expect(snapshot.previousUrl).toBe('https://sxlan.test/page-1');
        expect(snapshot.previousCorrelationId).toBe(
            '01K5MZ6V4N8YQ2W3J7R9T1X0A1',
        );
        expect(snapshot.recentVisits).toHaveLength(2);
        expect(snapshot.clientSentAt).toBe('2026-09-21T10:05:00.000Z');
    });

    it('drops a page correlation id the client made up', () => {
        const snapshot = snapshotContext(emptyContextState(), {
            pageCorrelationId: '<script>',
            href: 'https://sxlan.test/',
            viewport: null,
            now: '2026-09-21T10:05:00.000Z',
        });

        expect(snapshot.pageCorrelationId).toBeNull();
    });
});

/**
 * The disclosure is a promise (design §11): the person is told what goes
 * along *before* they send it, and the list has to keep naming the two
 * things that do not.
 */
describe('the "what we send" disclosure', () => {
    it('names the server-derived fields and what is never collected', () => {
        const fields = disclosedFields(
            snapshotContext(emptyContextState(), {
                pageCorrelationId: null,
                href: 'https://sxlan.test/',
                viewport: null,
                now: '2026-09-21T10:05:00.000Z',
            }),
        );

        const labels = fields.map((field) => field.label);

        expect(labels).toContain('From the server');
        expect(labels).toContain('Never sent');

        const never = fields.find((field) => field.label === 'Never sent');

        expect(never?.value).toContain('screenshots');
        expect(never?.value).toContain('IP address');
    });
});
