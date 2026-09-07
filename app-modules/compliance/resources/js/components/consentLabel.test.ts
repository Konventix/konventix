import { describe, expect, it } from 'vitest';
import { consentLabelSegments, consentLabelText } from './consentLabel';

/**
 * Fix round 1: the label used to render as "I have read and accept
 * theTerms of Participation andWithdrawal Information." — Vue's default
 * `whitespace: 'condense'` drops a whitespace-only text node between two
 * elements, so every space that sat between a static word and a `<Link>` in
 * the template was gone by the time it compiled.
 *
 * These assertions are about the spaces. Vitest here runs plain TypeScript
 * with no Vue and no DOM (see `vitest.config.ts`), which is exactly why the
 * wording lives in a pure function rather than in the template.
 */
const terms = {
    slug: 'terms',
    name: 'Terms of Participation',
    url: '/policies/terms',
};

const withdrawal = {
    slug: 'withdrawal',
    name: 'Withdrawal Information',
    url: '/policies/withdrawal',
};

describe('consentLabelText', () => {
    it('separates the lead-in from the first document', () => {
        expect(consentLabelText([terms])).toContain('accept the Terms');
    });

    it('reads as a sentence for the two purchase texts', () => {
        expect(consentLabelText([terms, withdrawal])).toBe(
            'I have read and accept the Terms of Participation and Withdrawal Information.',
        );
    });

    it('commas all but the last of three', () => {
        expect(
            consentLabelText([
                terms,
                withdrawal,
                { ...terms, name: 'House Rules' },
            ]),
        ).toBe(
            'I have read and accept the Terms of Participation, Withdrawal Information and House Rules.',
        );
    });

    it('writes no label at all when nothing is published', () => {
        expect(consentLabelText([])).toBe('');
    });
});

describe('consentLabelSegments', () => {
    it('keeps each document a link and everything else plain text', () => {
        const segments = consentLabelSegments([terms, withdrawal]);

        expect(segments.map((segment) => segment.kind)).toEqual([
            'text',
            'link',
            'text',
            'link',
            'text',
        ]);

        expect(segments[1]).toEqual({
            kind: 'link',
            text: 'Terms of Participation',
            url: '/policies/terms',
        });
    });
});
