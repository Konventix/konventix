import { describe, expect, it } from 'vitest';
import {
    supportCategoryLabel,
    supportCategoryLabels,
    supportCategorySummary,
    supportStateClass,
    supportStateClasses,
    supportStateLabel,
    supportStateLabels,
} from './supportConsole';

/**
 * The console's copy for Support's two enums.
 *
 * These labels are a copy of the PHP enums' `label()` methods, which is the
 * only reason they can be tested without a running application — so the
 * tests that matter are the ones that would catch the copy drifting: the
 * exact set of keys, and the exact words the dialog and the metric labels
 * already use.
 */
describe('the five categories', () => {
    it('names exactly the cases the enum has, in its own order', () => {
        expect(Object.keys(supportCategoryLabels)).toEqual([
            'purchase_affected',
            'visibility',
            'function',
            'display',
            'suggestion',
        ]);
    });

    it('uses the same words the report dialog offers', () => {
        expect(supportCategoryLabel('purchase_affected')).toBe(
            'Purchase affected',
        );
        expect(supportCategoryLabel('function')).toBe(
            'Something does not work',
        );
    });

    it('joins a report’s categories into one line', () => {
        expect(supportCategorySummary(['purchase_affected', 'display'])).toBe(
            'Purchase affected, Something looks wrong',
        );
    });

    /**
     * The endpoint demands at least one category, so this never happens —
     * but an empty cell is a worse answer than an em dash if it ever does.
     */
    it('answers an em dash rather than an empty cell', () => {
        expect(supportCategorySummary([])).toBe('—');
    });
});

describe('the five states', () => {
    it('names exactly the cases the enum has', () => {
        expect(Object.keys(supportStateLabels)).toEqual([
            'received',
            'seen',
            'in_progress',
            'resolved',
            'wont_fix',
        ]);
    });

    it('spells the terminal ones the way the enum does', () => {
        expect(supportStateLabel('in_progress')).toBe('In progress');
        expect(supportStateLabel('wont_fix')).toBe("Won't fix");
    });

    /**
     * Semantic tokens only (ADR-0008): no palette utility may appear here,
     * and `konventix/no-palette-colors` cannot see into a string.
     */
    it('colours every state with semantic tokens', () => {
        for (const classes of Object.values(supportStateClasses)) {
            expect(classes).not.toMatch(
                /\b(?:bg|text|border)-(?:zinc|gray|slate|neutral|stone|red|green|blue|amber|yellow)-\d{2,3}\b/,
            );
        }
    });

    /**
     * A report nobody has opened is the one state that says somebody is
     * late — the same thing `support_requests_unseen_oldest_age_seconds`
     * measures — so it is the warning colour and `seen` is not.
     */
    it('warns only about a report nobody has opened', () => {
        expect(supportStateClass('received')).toContain('warning');
        expect(supportStateClass('seen')).not.toContain('warning');
        expect(supportStateClass('resolved')).toContain('success');
    });
});
