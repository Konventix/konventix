import { computed, ref } from 'vue';

/**
 * One question before a click that cannot be taken back (final review,
 * part 3, I2): withdrawing a team, leaving one, declining, withdrawing or
 * disqualifying an entrant, cancelling or finishing a competition, redrawing
 * a bracket. The page asks through `ask()`, `ConfirmDialog` shows the
 * question on the repo's dialog primitive, and the action runs only on the
 * confirm button — never on Escape, the overlay, or the cancel button.
 *
 * The copy is the caller's: participant pages ask in German, the console in
 * English (ADR-0001).
 */
export type Confirmation = {
    title: string;
    description: string;
    confirmLabel: string;
    cancelLabel: string;
    /** Destructive styling for the confirm button; true unless said otherwise. */
    destructive?: boolean;
    run: () => void;
};

export function useConfirmation() {
    const pending = ref<Confirmation | null>(null);

    const open = computed({
        get: () => pending.value !== null,
        set: (value: boolean) => {
            if (!value) {
                pending.value = null;
            }
        },
    });

    function ask(confirmation: Confirmation): void {
        pending.value = confirmation;
    }

    function confirm(): void {
        const run = pending.value?.run;

        pending.value = null;
        run?.();
    }

    return { pending, open, ask, confirm };
}
