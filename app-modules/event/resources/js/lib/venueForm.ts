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
import { trans } from 'laravel-vue-i18n';

type Mutable<T> = { -readonly [K in keyof T]: T[K] };

export type VenuePlaceForm = Mutable<Konventix.Event.Data.VenuePlaceData>;

export type OpeningPeriodForm = Mutable<Konventix.Event.Data.OpeningPeriodData>;

export type VenueForm = Omit<
    Mutable<Konventix.Event.Data.VenueData>,
    'parkingSpots' | 'loadingZones' | 'openingTimes'
> & {
    parkingSpots: VenuePlaceForm[];
    loadingZones: VenuePlaceForm[];
    openingTimes: OpeningPeriodForm[];
};

/** A blank row for the repeatable place lists. */
export function emptyPlace(): VenuePlaceForm {
    return { label: '', address: null, latitude: null, longitude: null };
}

/**
 * The weekdays an opening period can fall on, in the order they are
 * offered. `dayOfWeek` is the ISO-8601 number `OpeningPeriodData` validates
 * (1 Monday through 7 Sunday), so Monday leads and Sunday closes the week —
 * not the Sunday-first order a `Date` would give.
 *
 * A function, not a constant: the labels come from the catalog, which is
 * loaded after the modules are imported (ADR-0068).
 */
export function weekdays(): ReadonlyArray<{ value: number; label: string }> {
    return [
        { value: 1, label: trans('Monday') },
        { value: 2, label: trans('Tuesday') },
        { value: 3, label: trans('Wednesday') },
        { value: 4, label: trans('Thursday') },
        { value: 5, label: trans('Friday') },
        { value: 6, label: trans('Saturday') },
        { value: 7, label: trans('Sunday') },
    ];
}

/**
 * A blank opening period. It starts on the day after the last one in the
 * list — a site is usually opened for a run of days, and typing Tuesday
 * over a prefilled Monday is cheaper than picking every day from scratch.
 * The week does not wrap: past Sunday the new row is Sunday again, and the
 * operator moves it.
 */
export function emptyOpeningPeriod(
    periods: readonly OpeningPeriodForm[] = [],
): OpeningPeriodForm {
    const previous = periods[periods.length - 1];

    return {
        dayOfWeek: previous ? Math.min(previous.dayOfWeek + 1, 7) : 1,
        opensAt: previous?.opensAt ?? '09:00',
        closesAt: previous?.closesAt ?? '18:00',
    };
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
