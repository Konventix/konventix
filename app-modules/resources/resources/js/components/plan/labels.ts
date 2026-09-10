/**
 * Label visibility and the sentences the live region speaks (design §7.4:
 * labels hidden below a zoom threshold; `role="status"` announcements).
 * Per-place `<text>` at every zoom was a measurable DOM cost in LanCore
 * (EditorCanvas.vue:639-674 rendered one per seat); here they only exist
 * once they are legible.
 */
export const LABEL_MIN_PX = 9;
export const DEFAULT_LABEL_MM = 250;

/**
 * 2026-09-10 batch amendment, ahead of design §12b/#282's quota mechanism:
 * a category with nothing free is its own explicit state, never a bare
 * validation error met only after trying to pick. One constant so the
 * picker (`Pick.vue`) and "Meine Registrierung" (`SeatSummarySlot.vue`)
 * never word it differently for the same claim.
 */
export const NO_FREE_PLACES_MESSAGE =
    'There are no free places in your categories right now.';

/** `pxPerUnit` is screen pixels per viewBox millimetre at zoom 1. */
export function labelsVisible(
    zoom: number,
    pxPerUnit: number,
    fontMm: number = DEFAULT_LABEL_MM,
): boolean {
    return fontMm * pxPerUnit * zoom >= LABEL_MIN_PX;
}

export function placeLabel(element: {
    label: string | null;
    ref: string | null;
}): string {
    return element.label ?? element.ref ?? '';
}

export function containerLabel(
    element: { label: string | null; ref: string | null },
    fallback: string,
): string {
    return element.label ?? element.ref ?? fallback;
}

export function selectionAnnouncement(
    refs: string[],
    quantity: number,
): string {
    if (refs.length === 0) {
        return `No place selected. You can choose ${quantity} ${quantity === 1 ? 'place' : 'places'}.`;
    }

    const list = refs.join(', ');

    return quantity === 1
        ? `Selected ${list}.`
        : `Selected ${list} (${refs.length} of ${quantity}).`;
}
