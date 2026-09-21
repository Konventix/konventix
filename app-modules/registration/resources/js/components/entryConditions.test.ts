import { describe, expect, it } from 'vitest';
import {
    assertionTextBudget,
    entryConditionDescribedBy,
    entryConditionErrorKey,
    entryConditionsSatisfied,
    missingEntryConditions,
    toggleEntryCondition,
} from './entryConditions';
import type { EntryConditionField } from './entryConditions';

/**
 * The entry-condition checkboxes, proved where they are provable: vitest
 * runs in the `node` environment with no `@vue/test-utils`, so the DOM
 * wiring is the Pest page tests' job and the rules are these functions'
 * (.ai/rules → js).
 */
const student: EntryConditionField = {
    id: '01HSTUDENT',
    key: 'student',
    name: 'Student ticket',
    assertionText:
        'I confirm that I am enrolled as a student and will present a valid student ID at the door.',
    documentHint: 'Valid student ID or enrolment certificate.',
};

const member: EntryConditionField = {
    id: '01HMEMBER',
    key: 'member',
    name: 'Member rate',
    assertionText: 'I confirm that I am a member of the association.',
    documentHint: null,
};

describe('what blocks a submission', () => {
    it('blocks while a condition is unticked', () => {
        expect(missingEntryConditions([student, member], [])).toEqual([
            student.id,
            member.id,
        ]);
        expect(entryConditionsSatisfied([student, member], [])).toBe(false);
        expect(entryConditionsSatisfied([student, member], [student.id])).toBe(
            false,
        );
    });

    it('releases once every condition is ticked', () => {
        expect(
            entryConditionsSatisfied(
                [student, member],
                [student.id, member.id],
            ),
        ).toBe(true);
    });

    it('never blocks a type that demands nothing', () => {
        expect(entryConditionsSatisfied([], [])).toBe(true);
    });

    it('ignores a ticked id the type does not demand', () => {
        expect(entryConditionsSatisfied([student], [student.id, 'stray'])).toBe(
            true,
        );
    });
});

describe('ticking', () => {
    it('adds once and removes cleanly', () => {
        const once = toggleEntryCondition([], student.id, true);
        expect(once).toEqual([student.id]);
        expect(toggleEntryCondition(once, student.id, true)).toEqual([
            student.id,
        ]);
        expect(toggleEntryCondition(once, student.id, false)).toEqual([]);
    });
});

describe('what the label is wired to', () => {
    it('names the hint and the error in that order', () => {
        expect(
            entryConditionDescribedBy('abc', { hasHint: true, hasError: true }),
        ).toBe('entry-condition-abc-hint entry-condition-abc-error');
    });

    it('is undefined rather than empty when there is neither', () => {
        expect(
            entryConditionDescribedBy('abc', {
                hasHint: false,
                hasError: false,
            }),
        ).toBeUndefined();
    });

    it('keys the error the way the server refuses', () => {
        expect(entryConditionErrorKey(student.id)).toBe(
            `entryConditions.${student.id}`,
        );
    });
});

describe('the console character budget', () => {
    it('counts down and flags a sentence past the cap', () => {
        expect(assertionTextBudget('abc', 10)).toEqual({
            length: 3,
            max: 10,
            remaining: 7,
            overBudget: false,
        });
        expect(assertionTextBudget('abcdefghijk', 10).overBudget).toBe(true);
    });
});
