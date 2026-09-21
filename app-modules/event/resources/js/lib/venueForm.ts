/**
 * The venue form's own types and the list moves its cards make, pure and
 * testable (.ai/rules/js.md: push logic into pure modules, prove the DOM
 * wiring with the Pest page tests).
 *
 * The shapes come from `VenueData` (ADR-0009 — never hand-write a type for
 * a backend payload); `Mutable` only strips the `readonly` the transformer
 * puts on every generated property, which `useForm` assigns straight
 * through.
 */
type Mutable<T> = { -readonly [K in keyof T]: T[K] };

export type VenuePlaceForm = Mutable<Konventix.Event.Data.VenuePlaceData>;

export type VenueForm = Omit<
    Mutable<Konventix.Event.Data.VenueData>,
    'parkingSpots' | 'loadingZones'
> & {
    parkingSpots: VenuePlaceForm[];
    loadingZones: VenuePlaceForm[];
};

/** A blank row for the repeatable place lists. */
export function emptyPlace(): VenuePlaceForm {
    return { label: '', address: null, latitude: null, longitude: null };
}

/**
 * Move one picture by `offset` places, returning a new list. A move off
 * either end is refused rather than wrapped: the buttons are disabled
 * there, and an order that jumps from last to first on a stray click is
 * not what anyone meant.
 */
export function moveImage(
    ids: string[],
    index: number,
    offset: number,
): string[] {
    const target = index + offset;

    if (
        index < 0 ||
        index >= ids.length ||
        target < 0 ||
        target >= ids.length
    ) {
        return [...ids];
    }

    const next = [...ids];
    const [moved] = next.splice(index, 1);
    next.splice(target, 0, moved);

    return next;
}

/**
 * Put a chosen picture in one slot: an empty id removes the slot, a
 * duplicate of a picture already in the list is refused (the same picture
 * twice is never what a picker click meant), and a new id replaces
 * whatever was there.
 */
export function setImageAt(
    ids: string[],
    index: number,
    mediaId: string | null,
): string[] {
    if (mediaId === null) {
        return ids.filter((_, position) => position !== index);
    }

    if (ids.includes(mediaId)) {
        return [...ids];
    }

    const next = [...ids];
    next[index] = mediaId;

    return next;
}

/**
 * The primary after a list change: the one named while it is still in the
 * list, otherwise the first picture, otherwise none. The same rule the
 * `Venue` model applies on save, so the form never shows a primary the
 * server would silently move.
 */
export function resolvePrimary(
    ids: string[],
    primary: string | null,
): string | null {
    if (primary !== null && ids.includes(primary)) {
        return primary;
    }

    return ids[0] ?? null;
}
