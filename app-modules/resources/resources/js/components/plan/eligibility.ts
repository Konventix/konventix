import { trans } from 'laravel-vue-i18n';

/**
 * Whether a place can be picked, and why not (design §7.4: "taken" versus
 * "not in your category", inline, never a modal — LanCore Picker.vue:348-408
 * drew that distinction and users understood it). Mirrors the server's
 * rules in `AssignPlacesToRegistration` so the page refuses first and the
 * Action refuses last; the constraint decides a race the page could not
 * see.
 *
 * A free place at the selection cap is `swap`, not a refusal (2026-09-21
 * bugfix round): clicking it drops the oldest selection and takes this one
 * (`selectWithSwap`), the way a person expects "click another seat" to
 * work. Nothing is persisted until the "Save my seat" form posts the whole
 * set, and the Action releases the old bookings as `repicked` — so the
 * swap costs the page nothing it did not already do on a re-pick.
 */
type PlaceState = Konventix.Resources.Enums.PlaceState;

export type Reason =
    | 'free'
    | 'selected'
    | 'mine'
    | 'taken'
    | 'not-in-category'
    | 'unavailable'
    | 'swap';

export type PlaceContext = {
    placeId: string;
    resourceTypeId: string | null;
    state: PlaceState | undefined;
    claimTypeIds: string[];
    selectedIds: string[];
    quantity: number;
};

export function placeReason(context: PlaceContext): Reason {
    if (context.selectedIds.includes(context.placeId)) {
        return 'selected';
    }

    if (
        context.resourceTypeId === null ||
        !context.claimTypeIds.includes(context.resourceTypeId)
    ) {
        return 'not-in-category';
    }

    switch (context.state) {
        case 'mine':
            return 'mine';
        case 'held':
        case 'booked':
            return 'taken';
        case 'free':
            return context.selectedIds.length >= context.quantity
                ? 'swap'
                : 'free';
        default:
            return 'unavailable';
    }
}

export function isSelectable(reason: Reason): boolean {
    return (
        reason === 'free' ||
        reason === 'selected' ||
        reason === 'mine' ||
        reason === 'swap'
    );
}

/**
 * A lookup at call time, never a module constant: the words are catalog
 * entries, and the catalog is loaded after this module is imported
 * (ADR-0068).
 */
export function stateText(reason: Reason): string {
    const texts: Record<Reason, string> = {
        free: trans('Free'),
        selected: trans('Selected'),
        mine: trans('Yours'),
        taken: trans('Taken'),
        'not-in-category': trans('Not in your category'),
        unavailable: trans('Unavailable'),
        swap: trans('Free, swaps'),
    };

    return texts[reason];
}

export function hintFor(reason: Reason): string | null {
    switch (reason) {
        case 'taken':
            return trans('This place is already taken.');
        case 'not-in-category':
            return trans('This place is not in your category.');
        case 'unavailable':
            return trans('This place is not available.');
        default:
            return null;
    }
}

/**
 * The next selection after activating `placeId`: present → removed; under
 * the cap → appended; at the cap → the oldest selection leaves and this
 * one is appended (`quantity` 1 is a plain replace). Order is selection
 * order, which is what "oldest" means here. A cap of zero or less selects
 * nothing.
 */
export function selectWithSwap(
    selected: string[],
    placeId: string,
    quantity: number,
): string[] {
    if (selected.includes(placeId)) {
        return selected.filter((id) => id !== placeId);
    }

    if (quantity <= 0) {
        return selected;
    }

    if (selected.length < quantity) {
        return [...selected, placeId];
    }

    return [...selected.slice(selected.length - quantity + 1), placeId];
}

/**
 * After an availability poll: a selected place that is no longer free (and
 * not the registrant's own) leaves the selection, and the caller tells the
 * person so — a silent drop and a 422 on submit would be the same surprise
 * twice.
 */
export function reconcileSelection(
    selected: string[],
    availability: Record<string, PlaceState>,
): { selected: string[]; lost: string[] } {
    const lost = selected.filter((id) => {
        const state = availability[id];

        return state !== 'free' && state !== 'mine';
    });

    return { selected: selected.filter((id) => !lost.includes(id)), lost };
}

/**
 * Whether any place of one of the claim's categories is pickable right
 * now — free, or already the registrant's own. Pure so `Pick.vue` can run
 * it against the polled availability map on every 15 s tick, not only the
 * page's initial props (2026-09-10 review fix): a `noFreePlaces` flag
 * computed once at render time went stale the moment a held place
 * expired or was released while the person sat on the page, so the
 * picker's own map and its "nothing is free" banner could disagree with
 * each other. Mirrors the server's `HasFreePlaceForClaim`, which stays
 * the source of truth for the registrant's account page — that page has
 * no live poll to derive from.
 */
export function categoriesHaveFreePlace(
    placeElements: { id: string; resourceTypeId: string | null }[],
    availability: Record<string, PlaceState>,
    claimTypeIds: string[],
): boolean {
    return placeElements.some(
        (element) =>
            element.resourceTypeId !== null &&
            claimTypeIds.includes(element.resourceTypeId) &&
            (availability[element.id] === 'free' ||
                availability[element.id] === 'mine'),
    );
}

/** Laravel keys an array-field error on the field or on `field.index`; the page shows the first. */
export function firstPlaceIdsError(
    errors: Record<string, string>,
): string | undefined {
    if (errors.placeIds) {
        return errors.placeIds;
    }

    return Object.entries(errors).find(([key]) =>
        key.startsWith('placeIds.'),
    )?.[1];
}
