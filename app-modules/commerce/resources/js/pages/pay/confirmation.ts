/**
 * The one decision the confirmation panes make that is worth testing
 * without a DOM (vitest runs in the `node` environment here).
 */

export type ConfirmationSlot = {
    module: string;
    component: string;
    props: Record<string, unknown>;
};

type NamedText = { slug?: unknown; name?: unknown };

/**
 * A stable string for "which texts is this page asking about, and does it
 * want them agreed to".
 *
 * The confirmation pane watches it and clears the acceptance whenever it
 * changes (D-CPL-07): a payer may not be held to have agreed to a list they
 * never saw, and a partial reload that brings back a different list — a
 * republished version, a provider changed in another tab — would otherwise
 * leave the tick standing over new text.
 *
 * Slugs rather than names: a reworded title is the same document, and
 * clearing the tick for it would be noise. The `required` flag is part of
 * the signature because agree-versus-inform is itself a change of what is
 * being asked (D-CPL-08).
 */
export function consentListSignature(slots: ConfirmationSlot[]): string {
    return slots
        .map((slot) => {
            const required = slot.props.required === true ? '!' : '?';
            const slugs = [
                ...slugsOf(slot.props.policies),
                ...slugsOf(slot.props.notices),
            ];

            return `${slot.component}${required}${slugs.join(',')}`;
        })
        .join('|');
}

function slugsOf(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.map((entry) => {
        const text = entry as NamedText;

        return typeof text.slug === 'string' ? text.slug : '';
    });
}
