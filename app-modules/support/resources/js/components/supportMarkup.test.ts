import { describe, expect, it } from 'vitest';
import launcherMarkup from './SupportLauncher.vue?raw';
import dialogMarkup from './SupportRequestDialog.vue?raw';

/**
 * The accessibility contract of the launcher and the dialog (design §12,
 * ADR-0055), asserted against the markup itself.
 *
 * Vitest runs in the `node` environment on purpose (`vitest.config.ts`
 * explains why), so there is no DOM to mount into and no jsdom to pull in
 * for two components. The components come in through Vite's `?raw`, not
 * through `node:fs`: `tsconfig.json` deliberately exposes only
 * `vite/client`'s ambient types, so a `node:fs` import type-checks
 * nowhere. Reading the single-file components as text is the honest
 * version of what is being checked here anyway: these are *static*
 * promises — an `aria-label` that exists, a `role="group"`, a target big
 * enough to hit — and the way they get lost is somebody deleting an
 * attribute during a refactor, which this catches exactly as well as a
 * rendered tree would.
 *
 * What it deliberately does not claim to check: focus order, the focus
 * trap and the return of focus on Escape. Those are reka-ui's, exercised by
 * every other dialog in the application, and a string match would only
 * pretend to cover them. S3's a11y pass (#307) is where the whole flow is
 * walked.
 */
describe('SupportLauncher.vue', () => {
    const markup = launcherMarkup;

    it('is a real button, so Enter and Space open the dialog without help', () => {
        expect(markup).toContain('<button');
        expect(markup).toContain('type="button"');
    });

    it('has an accessible name even when only the icon is visible', () => {
        expect(markup).toContain(
            'aria-label="Report a problem or give feedback"',
        );
        // The icon itself carries no name of its own, or it would be read
        // twice.
        expect(markup).toContain('aria-hidden="true"');
    });

    it('announces that it opens a dialog, and whether it is open', () => {
        expect(markup).toContain('aria-haspopup="dialog"');
        expect(markup).toContain(':aria-expanded="dialog.isOpen.value"');
    });

    /** ADR-0055: 44 px in both dimensions, at every viewport width. */
    it('keeps a 44 px touch target', () => {
        expect(markup).toContain('min-h-11');
        expect(markup).toContain('min-w-11');
    });

    it('keeps a visible focus ring and stays out of print', () => {
        expect(markup).toContain('focus-visible:ring-');
        expect(markup).toContain('print:hidden');
    });

    it('uses semantic tokens only (ADR-0008)', () => {
        expect(markup).not.toMatch(/\b(bg|text|border)-(zinc|gray|slate)-\d/);
    });
});

describe('SupportRequestDialog.vue', () => {
    const markup = dialogMarkup;

    it('takes its accessible name from the dialog title', () => {
        expect(markup).toContain(
            '<DialogTitle>Report a problem or give feedback</DialogTitle>',
        );
    });

    it('renders the category chips as a labelled group of toggle buttons', () => {
        expect(markup).toContain('role="group"');
        expect(markup).toContain(
            ':aria-pressed="categories.includes(option.value)"',
        );
    });

    it('wires the counter and the error to the textarea', () => {
        expect(markup).toContain(':aria-describedby="messageDescribedBy"');
        expect(markup).toContain(
            ':aria-invalid="errors.message ? \'true\' : undefined"',
        );
    });

    it('hides the honeypot from people and from autofill, without hiding a real field', () => {
        expect(markup).toContain('aria-hidden="true"');
        expect(markup).toContain('class="sr-only"');
        expect(markup).toContain('tabindex="-1"');
        expect(markup).toContain('autocomplete="off"');
        expect(markup).toContain('name="website"');
    });

    it('announces a transport failure rather than only showing it', () => {
        expect(markup).toContain('role="alert"');
    });

    it('keeps the §6.2 order: categories, message, address, consent, disclosure, honeypot, footer', () => {
        const order = [
            'What is this about?',
            'What happened?',
            'Your e-mail address',
            'You may contact me about this report.',
            'What we send with your report',
            'name="website"',
            '<DialogFooter>',
        ].map((needle) => markup.indexOf(needle));

        expect(order).not.toContain(-1);
        expect(order).toEqual([...order].sort((a, b) => a - b));
    });

    it('uses semantic tokens only (ADR-0008)', () => {
        expect(markup).not.toMatch(/\b(bg|text|border)-(zinc|gray|slate)-\d/);
    });
});
