import { describe, expect, it } from 'vitest';
import {
    acknowledge,
    becameEnabled,
    canFinish,
    finish,
    isRevealing,
    noCodesRevealed,
    revealCodes,
} from './recoveryCodesReveal';

/**
 * Bugfix round 2026-09-21, Task 13: recovery codes are shown once, behind a
 * "I saved my recovery codes" checkbox that gates the only way to close the
 * dialog. These pin the gate; the DOM wiring is proven by the Pest page tests.
 */
const codes = ['aaaa-bbbb', 'cccc-dddd'];

describe('revealCodes', () => {
    it('opens on a fresh, unacknowledged set', () => {
        const state = revealCodes(codes);

        expect(isRevealing(state)).toBe(true);
        expect(state.acknowledged).toBe(false);
        expect(canFinish(state)).toBe(false);
    });

    it('drops an earlier acknowledgement when a new set arrives', () => {
        const saved = acknowledge(revealCodes(codes), true);

        expect(canFinish(revealCodes(['eeee-ffff']))).toBe(false);
        expect(saved.acknowledged).toBe(true);
    });

    it('copies the list rather than holding the caller’s array', () => {
        const input = [...codes];
        const state = revealCodes(input);
        input.push('gggg-hhhh');

        expect(state.codes).toEqual(codes);
    });
});

describe('finish', () => {
    it('stays open until the box is ticked', () => {
        const state = revealCodes(codes);

        expect(finish(state)).toBe(state);
        expect(isRevealing(finish(state))).toBe(true);
    });

    it('closes once the box is ticked', () => {
        const state = acknowledge(revealCodes(codes), true);

        expect(canFinish(state)).toBe(true);
        expect(finish(state)).toEqual(noCodesRevealed);
    });

    it('re-locks when the box is unticked again', () => {
        const state = acknowledge(acknowledge(revealCodes(codes), true), false);

        expect(canFinish(state)).toBe(false);
        expect(finish(state)).toBe(state);
    });

    it('is a no-op when nothing is on show', () => {
        expect(finish(noCodesRevealed)).toBe(noCodesRevealed);
        expect(canFinish(acknowledge(noCodesRevealed, true))).toBe(false);
    });
});

describe('becameEnabled', () => {
    it('is the transition from not enabled to enabled', () => {
        expect(becameEnabled(false, true)).toBe(true);
    });

    it('is not an ordinary visit of an already enabled account', () => {
        expect(becameEnabled(true, true)).toBe(false);
        expect(becameEnabled(undefined, true)).toBe(false);
    });

    it('is not a disablement', () => {
        expect(becameEnabled(true, false)).toBe(false);
        expect(becameEnabled(false, false)).toBe(false);
    });
});
