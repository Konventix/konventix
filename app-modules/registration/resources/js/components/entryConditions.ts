/**
 * The entry-condition checkboxes, as logic rather than markup (ADR-0066
 * §6.2, §9.3).
 *
 * Everything here is pure, for one reason: vitest runs in the `node`
 * environment with no `@vue/test-utils`, so what a test can prove is what
 * does not need a DOM (`.ai/rules/index.md` → js). The wiring of these
 * values into a `<label>`, an `aria-describedby` and a disabled button is
 * proven by the Pest page tests instead.
 *
 * Imports nothing from `@/routes/*` on purpose — a vitest module may not
 * pull in the generated route helpers.
 */

/** The public shape, structurally — the generated Data type minus Inertia. */
export type EntryConditionField = {
    id: string;
    key: string;
    name: string;
    assertionText: string;
    documentHint: string | null;
};

/** The id of the checkbox itself, and of the two things that describe it. */
export function entryConditionInputId(conditionId: string): string {
    return `entry-condition-${conditionId}`;
}

export function entryConditionHintId(conditionId: string): string {
    return `entry-condition-${conditionId}-hint`;
}

export function entryConditionErrorId(conditionId: string): string {
    return `entry-condition-${conditionId}-error`;
}

/**
 * The validation key the server refuses on (`entryConditions.{id}`), so the
 * message lands on the checkbox that raised it rather than in a banner
 * (ADR-0055).
 */
export function entryConditionErrorKey(conditionId: string): string {
    return `entryConditions.${conditionId}`;
}

/**
 * What `aria-describedby` must name: the document hint where there is one,
 * and the error where there is one — in that order, because the hint
 * explains the ask and the error explains the refusal.
 *
 * Returns undefined rather than an empty string when there is neither: an
 * empty `aria-describedby` points at nothing and is worse than none.
 */
export function entryConditionDescribedBy(
    conditionId: string,
    options: { hasHint: boolean; hasError: boolean },
): string | undefined {
    const ids = [
        options.hasHint ? entryConditionHintId(conditionId) : null,
        options.hasError ? entryConditionErrorId(conditionId) : null,
    ].filter((id): id is string => id !== null);

    return ids.length > 0 ? ids.join(' ') : undefined;
}

/**
 * Ticking and unticking, as a set operation on the posted list. The order
 * the boxes were ticked in carries no meaning, so a re-tick never duplicates
 * and an untick never leaves a hole.
 */
export function toggleEntryCondition(
    accepted: string[],
    conditionId: string,
    checked: boolean,
): string[] {
    return checked
        ? [...new Set([...accepted, conditionId])]
        : accepted.filter((id) => id !== conditionId);
}

/**
 * The conditions this submission has not declared. Every one of them is
 * required — a reduced fare rests on the declaration, so there is no
 * optional box here — and the server refuses the same set again under lock
 * (`SubmitRegistration::guardEntryConditions()`).
 */
export function missingEntryConditions(
    conditions: EntryConditionField[],
    accepted: string[],
): string[] {
    const ticked = new Set(accepted);

    return conditions
        .filter((condition) => !ticked.has(condition.id))
        .map((condition) => condition.id);
}

/**
 * Whether the form may be submitted at all. A type that demands nothing
 * submits exactly as it did before this feature existed.
 */
export function entryConditionsSatisfied(
    conditions: EntryConditionField[],
    accepted: string[],
): boolean {
    return missingEntryConditions(conditions, accepted).length === 0;
}

/**
 * The console editor's character budget on the assertion text (spec §9.1).
 *
 * A sentence somebody has to read beside a checkbox has a length past which
 * nobody reads it, and a three-paragraph declaration is a dark pattern
 * rather than a longer one. The server cap (`EntryConditionData`, 500) is
 * the hard limit; this is what the editor shows while it is being typed.
 */
export function assertionTextBudget(
    text: string,
    max = 500,
): { length: number; max: number; remaining: number; overBudget: boolean } {
    const length = text.length;

    return {
        length,
        max,
        remaining: max - length,
        overBudget: length > max,
    };
}
