/**
 * Whether a place can be picked, and why not (design §7.4: "taken" versus
 * "not in your category" versus "limit reached", inline, never a modal —
 * LanCore Picker.vue:348-408 drew that distinction and users understood
 * it). Mirrors the server's rules in `AssignPlacesToRegistration` so the
 * page refuses first and the Action refuses last; the constraint decides
 * a race the page could not see.
 */
type PlaceState = Konventix.Resources.Enums.PlaceState;

export type Reason =
    | 'free'
    | 'selected'
    | 'mine'
    | 'taken'
    | 'not-in-category'
    | 'unavailable'
    | 'limit-reached';

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
                ? 'limit-reached'
                : 'free';
        default:
            return 'unavailable';
    }
}

export function isSelectable(reason: Reason): boolean {
    return reason === 'free' || reason === 'selected' || reason === 'mine';
}

const STATE_TEXT: Record<Reason, string> = {
    free: 'Free',
    selected: 'Selected',
    mine: 'Yours',
    taken: 'Taken',
    'not-in-category': 'Not in your category',
    unavailable: 'Unavailable',
    'limit-reached': 'Limit reached',
};

export function stateText(reason: Reason): string {
    return STATE_TEXT[reason];
}

export function hintFor(reason: Reason, quantity: number): string | null {
    switch (reason) {
        case 'taken':
            return 'This place is already taken.';
        case 'not-in-category':
            return 'This place is not in your category.';
        case 'unavailable':
            return 'This place is not available.';
        case 'limit-reached':
            return quantity === 1
                ? 'You can choose 1 place. Unselect it first.'
                : `You can choose ${quantity} places. Unselect one first.`;
        default:
            return null;
    }
}

export function toggleSelection(
    selected: string[],
    placeId: string,
    quantity: number,
): string[] {
    if (selected.includes(placeId)) {
        return selected.filter((id) => id !== placeId);
    }

    if (selected.length >= quantity) {
        return selected;
    }

    return [...selected, placeId];
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
