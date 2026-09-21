/**
 * The state behind the recovery-codes dialog in `TwoFactorAuthentication.vue`
 * (bugfix round 2026-09-21, Task 13), as data rather than as template logic.
 *
 * The codes used to be fetched on every visit of the security page and listed
 * there permanently, which made "recovery codes" a thing to glance at rather
 * than a thing to put away. Now they appear exactly once — right after the
 * enrolment is confirmed, or right after a regeneration — in a dialog that
 * cannot be dismissed until the person says they saved them. The checkbox is
 * a nudge, not a security boundary: Fortify's GET route for the codes stays,
 * so a person who lied to the checkbox can still ask for them by regenerating.
 *
 * Vitest here runs plain TypeScript with no Vue and no DOM (`vitest.config.ts`),
 * which is why the gate lives in a pure module (`recoveryCodesReveal.test.ts`).
 */
export type RecoveryCodesReveal = {
    /** The codes on show; empty when the dialog is closed. */
    codes: string[];
    /** Whether "I saved my recovery codes" is ticked. */
    acknowledged: boolean;
};

export const noCodesRevealed: RecoveryCodesReveal = {
    codes: [],
    acknowledged: false,
};

/**
 * Open the dialog on a fresh set. Always unacknowledged: a tick given to the
 * previous set says nothing about this one.
 */
export function revealCodes(codes: readonly string[]): RecoveryCodesReveal {
    return { codes: [...codes], acknowledged: false };
}

export function isRevealing(state: RecoveryCodesReveal): boolean {
    return state.codes.length > 0;
}

export function acknowledge(
    state: RecoveryCodesReveal,
    saved: boolean,
): RecoveryCodesReveal {
    return { ...state, acknowledged: saved };
}

/** The Done button: only once the codes are on show and the box is ticked. */
export function canFinish(state: RecoveryCodesReveal): boolean {
    return isRevealing(state) && state.acknowledged;
}

/**
 * Close the dialog — but only through the gate. Escape, a click outside and
 * an unticked Done all land here and change nothing.
 */
export function finish(state: RecoveryCodesReveal): RecoveryCodesReveal {
    return canFinish(state) ? noCodesRevealed : state;
}

/**
 * The confirmation moment, read off the page props: the account was not
 * enabled and now is. A page that loads already enabled never passes through
 * this, which is what keeps the codes off the screen on an ordinary visit.
 */
export function becameEnabled(
    wasEnabled: boolean | undefined,
    isEnabled: boolean,
): boolean {
    return isEnabled && wasEnabled === false;
}
