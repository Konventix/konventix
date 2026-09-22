import { describe, expect, it } from 'vitest';
import {
    FEEDBACK_BUTTON_BOTTOM_PX,
    FEEDBACK_BUTTON_CLEARANCE_PX,
    FEEDBACK_BUTTON_HEIGHT_PX,
    toasterOffsets,
} from './toasterOffset';

/**
 * #319: the toaster's bottom edge clears the feedback button on phone and
 * desktop. Read through `import.meta.glob` (as `moduleImports.test.ts`
 * does) rather than imported: the shell may not import Support's files,
 * only check that the geometry it relies on is still the one it knows.
 */
const launcher = Object.values(
    import.meta.glob<string>(
        '/app-modules/support/resources/js/components/SupportLauncher.vue',
        { query: '?raw', import: 'default', eager: true },
    ),
)[0];

/** Every shell layout, to check each toaster it mounts obeys the rule. */
const layouts = import.meta.glob<string>('/resources/js/layouts/**/*.vue', {
    query: '?raw',
    import: 'default',
    eager: true,
});

/** Tailwind's spacing scale: one step is 0.25rem, 4 px at the root size. */
const TAILWIND_STEP_PX = 4;

describe('toasterOffsets', () => {
    it('lifts the toaster above the button on the desktop and on a phone while the button is mounted', () => {
        const offsets = toasterOffsets(true);

        expect(offsets.offset).toEqual({
            bottom: FEEDBACK_BUTTON_CLEARANCE_PX,
        });
        expect(offsets.mobileOffset).toEqual({
            bottom: FEEDBACK_BUTTON_CLEARANCE_PX,
        });
        expect(FEEDBACK_BUTTON_CLEARANCE_PX).toBeGreaterThan(
            FEEDBACK_BUTTON_BOTTOM_PX + FEEDBACK_BUTTON_HEIGHT_PX,
        );
    });

    it("leaves vue-sonner's own offsets alone when there is no button", () => {
        expect(toasterOffsets(false)).toEqual({});
    });

    it("mirrors the launcher button's position and height", () => {
        expect(launcher).toBeDefined();

        // The fixed button's own class list, not a mention in a comment.
        const buttonClass =
            /class="(fixed [^"]*)"/.exec(launcher ?? '')?.[1] ?? '';
        const bottom = /(?:^| )bottom-(\d+)(?: |$)/.exec(buttonClass);
        const height = /(?:^| )min-h-(\d+)(?: |$)/.exec(buttonClass);

        expect(Number(bottom?.[1]) * TAILWIND_STEP_PX).toBe(
            FEEDBACK_BUTTON_BOTTOM_PX,
        );
        expect(Number(height?.[1]) * TAILWIND_STEP_PX).toBe(
            FEEDBACK_BUTTON_HEIGHT_PX,
        );
    });

    it('is what every shell that mounts a toaster and the feedback button binds', () => {
        const shells = Object.entries(layouts).filter(
            ([, source]) =>
                source.includes('<Toaster') &&
                source.includes('support/SupportLauncher'),
        );

        expect(shells.map(([path]) => path).sort()).toEqual([
            '/resources/js/layouts/AdminLayout.vue',
            '/resources/js/layouts/AppTopbarLayout.vue',
        ]);

        for (const [, source] of shells) {
            expect(source).toContain(
                '<Toaster v-bind="toasterOffsets(supportLauncher !== null)" />',
            );
        }
    });
});
