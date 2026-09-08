/**
 * The policy version editor's date handling, kept out of the page so it can
 * be tested as what it is: two pure functions.
 *
 * Writing an effective date *out* is not one of them: that is the shared
 * `formatDateTime()` in `@/lib/datetime`, with `withZoneName` — three
 * implementations of the same formatting is what the branch review found
 * here, and one of them is enough.
 *
 * A `datetime-local` control speaks wall clock and nothing else — it hands
 * back `YYYY-MM-DDTHH:MM` with no zone attached. The server used to read that
 * string in `config('app.timezone')` (UTC), so an operator in Berlin typing
 * "now" got an `effective_at` one or two hours in the future, and
 * `/policies/{slug}` answered 404 until it arrived. The page therefore
 * resolves the wall clock against the operator's own zone before it sends,
 * and converts back on the way in.
 */

/**
 * The round trip itself moved to `@/lib/datetime` when #237 needed the same
 * pair for the sales window — one implementation, re-exported here so this
 * page and its test keep their own vocabulary.
 */
import { toLocalDateTimeInput as toLocalInput } from '@/lib/datetime';

export {
    toInstant,
    toLocalDateTimeInput as toLocalInput,
} from '@/lib/datetime';

/** The subset of a selected version the editor's form is built out of. */
export type SelectedVersion = {
    version: string;
    content: string;
    requiresConsent: boolean;
    lawfulBasis: string;
    effectiveAt: string | null;
};

export type VersionFormValues = {
    version: string;
    content: string;
    requiresConsent: boolean;
    lawfulBasis: string;
    effectiveAt: string;
};

/**
 * The form state for the version currently selected — the page's single
 * source for it, on first render and on every later change of selection.
 *
 * Inertia keeps the same page component across a redirect that renders the
 * same page, so `setup()` runs once for the whole session: after "New draft
 * version" redirected to the new draft's editor, the form still held the
 * previously opened version's label and saving wrote it onto the new row.
 * That violates `policy_versions(policy_id, version)` — a 500, with the draft
 * already created. The page re-seeds from here whenever the selection moves.
 */
export function versionFormValues(
    selected: SelectedVersion | null,
): VersionFormValues {
    return {
        version: selected?.version ?? '',
        content: selected?.content ?? '',
        requiresConsent: selected?.requiresConsent ?? true,
        lawfulBasis: selected?.lawfulBasis ?? 'consent',
        effectiveAt: toLocalInput(selected?.effectiveAt ?? null),
    };
}
