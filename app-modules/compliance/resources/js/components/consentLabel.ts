/**
 * The wording of the checkout consent label, as data rather than as template
 * markup (GitLab #2).
 *
 * Written this way because the markup version was wrong and looked right:
 * Vue's default `whitespace: 'condense'` drops a whitespace-only text node
 * between two elements, so a label built from `<template v-for>` around
 * `<Link>` rendered as "I have read and accept theTerms of Participation
 * andWithdrawal Information." — every space between a static word and a link
 * silently gone. Interpolated strings are expression nodes and survive
 * verbatim, so the separators live here, where they are also testable
 * (`consentLabel.test.ts`) without rendering a component.
 */
export type ConsentPolicy = {
    slug: string;
    name: string;
    url: string;
};

export type ConsentLabelSegment =
    | { kind: 'text'; text: string }
    | { kind: 'link'; text: string; url: string };

const LEAD = 'I have read and accept the ';

/**
 * The label as an ordered run of plain text and links: the lead-in, each
 * document's name as a link, the separators between them, and the full stop.
 *
 * Empty for an empty list — a checkout with nothing published renders no
 * control at all, so there is no label to write.
 */
export function consentLabelSegments(
    policies: ConsentPolicy[],
): ConsentLabelSegment[] {
    if (policies.length === 0) {
        return [];
    }

    const segments: ConsentLabelSegment[] = [{ kind: 'text', text: LEAD }];

    policies.forEach((policy, index) => {
        if (index > 0) {
            segments.push({
                kind: 'text',
                text: index === policies.length - 1 ? ' and ' : ', ',
            });
        }

        segments.push({ kind: 'link', text: policy.name, url: policy.url });
    });

    segments.push({ kind: 'text', text: '.' });

    return segments;
}

/**
 * The same label as one string — what a reader actually hears or sees once
 * the links are stripped, and therefore what a test can assert the spacing
 * of.
 */
export function consentLabelText(policies: ConsentPolicy[]): string {
    return consentLabelSegments(policies)
        .map((segment) => segment.text)
        .join('');
}
