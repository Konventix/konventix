import { trans } from 'laravel-vue-i18n';

type SupportCategory = Konventix.Support.Enums.SupportCategory;
type SupportRequestState = Konventix.Support.Enums.SupportRequestState;

/**
 * The words and colours the console uses for Support's two enums, in one
 * place and with no import but the catalog's `trans` — so the inbox table,
 * the detail page and vitest can each read them without pulling in
 * Wayfinder, Inertia or a component (`.ai/rules/js.md`: never import
 * `@/routes/*` from a module under test).
 *
 * The labels are deliberately a **copy** of the PHP enums' `label()`
 * methods rather than a prop fetched per page: five categories and five
 * states are a closed set that only grows through a code change (see
 * `SupportCategory`'s own docblock — they are metric labels), and shipping
 * ten strings per row to say what the enum already says would be worse.
 * `supportCategoryLabels`/`supportStateLabels` returning exhaustive
 * `Record`s is what makes the copy safe: adding a case to either enum
 * regenerates `generated.d.ts` and `npm run types:check` then fails here
 * until the word is added.
 *
 * English source, looked up in the catalog (ADR-0068). Functions rather
 * than constants, because the catalog is loaded after the modules are
 * imported.
 */
export function supportCategoryLabels(): Record<SupportCategory, string> {
    return {
        purchase_affected: trans('Purchase affected'),
        visibility: trans('Something is not visible'),
        function: trans('Something does not work'),
        display: trans('Something looks wrong'),
        suggestion: trans('Suggestion'),
    };
}

export function supportStateLabels(): Record<SupportRequestState, string> {
    return {
        received: trans('Received'),
        seen: trans('Seen'),
        in_progress: trans('In progress'),
        resolved: trans('Resolved'),
        wont_fix: trans("Won't fix"),
    };
}

/**
 * Semantic tokens only (ADR-0008). The scale says how much somebody still
 * owes: a report nobody has opened is the warning, one in hand is the
 * accent, resolved is the success, and a declined one is muted rather than
 * dangerous — "won't fix" is a decision, not a failure.
 */
export const supportStateClasses: Record<SupportRequestState, string> = {
    received: 'bg-warning/15 text-warning',
    seen: 'bg-subtle text-text',
    in_progress: 'bg-accent/15 text-accent',
    resolved: 'bg-success/15 text-success',
    wont_fix: 'bg-subtle text-text-muted',
};

export function supportCategoryLabel(category: SupportCategory): string {
    return supportCategoryLabels()[category] ?? category;
}

export function supportStateLabel(state: SupportRequestState): string {
    return supportStateLabels()[state] ?? state;
}

export function supportStateClass(state: SupportRequestState): string {
    return supportStateClasses[state] ?? 'bg-subtle text-text-muted';
}

/**
 * The categories of one report as one line. Empty never happens — the
 * endpoint demands at least one — but an em dash is a better answer than an
 * empty cell if a row ever arrives without any.
 */
export function supportCategorySummary(categories: SupportCategory[]): string {
    return categories.length === 0
        ? '—'
        : categories.map(supportCategoryLabel).join(', ');
}
