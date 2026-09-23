import { trans, transChoice } from 'laravel-vue-i18n';

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
 * Group and fixture labels in `PlanBackdrop.vue` (2026-09-21 bugfix round):
 * places carry no `<text>` any more — their label is the native `<title>`
 * tooltip and `aria-label` on the place `<g>` — so the containers around
 * them name the rows and tables instead, at a size that stays legible one
 * zoom step further out than a place label would.
 */
export const CONTAINER_LABEL_MM = 400;

/**
 * 2026-09-10 batch amendment, ahead of design §12b/#282's quota mechanism:
 * a category with nothing free is its own explicit state, never a bare
 * validation error met only after trying to pick. One function so the
 * picker (`Pick.vue`) and "Meine Registrierung" (`SeatSummarySlot.vue`)
 * never word it differently for the same claim.
 */
export function noFreePlacesMessage(): string {
    return trans('There are no free places in your categories right now.');
}

/**
 * Item 1 of the 2026-09-10 fix wave (#278): an `assign`-mode claim is
 * placed by the organiser, never by the registrant — the picker must not
 * render for one, and "Meine Registrierung" must not offer to open it.
 * One function, the same pattern as `noFreePlacesMessage`, so `Pick.vue`
 * and `SeatSummarySlot.vue` say the same true, calm thing rather than two
 * separately worded guesses.
 */
export function assignedByOrganiserMessage(): string {
    return trans('Your seat will be assigned by the organiser.');
}

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
        return transChoice(
            '{1} No place selected. You can choose :count place.|[0,*] No place selected. You can choose :count places.',
            quantity,
        );
    }

    const list = refs.join(', ');

    return quantity === 1
        ? trans('Selected :list.', { list })
        : trans('Selected :list (:selected of :quantity).', {
              list,
              selected: String(refs.length),
              quantity: String(quantity),
          });
}

/**
 * The words the editor prints for the wire values of `ElementKind` and
 * `FixtureType` (ADR-0068): the raw values showed English to every
 * locale. Looked up when asked for, never at import time, because the
 * catalog is loaded after the modules are imported. An unknown value falls
 * through unchanged.
 */
export function elementKindLabel(kind: string): string {
    const labels: Record<string, string> = {
        zone: trans('Zone'),
        group: trans('Group'),
        place: trans('Place'),
        fixture: trans('Fixture'),
        annotation: trans('Annotation'),
    };

    return labels[kind] ?? kind;
}

export function elementKindPluralLabel(kind: string): string {
    const labels: Record<string, string> = {
        zone: trans('Zones'),
        group: trans('Groups'),
        place: trans('Places'),
        fixture: trans('Fixtures'),
        annotation: trans('Annotations'),
    };

    return labels[kind] ?? kind;
}

export function fixtureTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        table: trans('Table'),
        counter: trans('Counter'),
        stage: trans('Stage'),
        wall: trans('Wall'),
        pillar: trans('Pillar'),
        custom: trans('Custom'),
    };

    return labels[type] ?? type;
}

/** A category's `CapacityMode`: one row per place, or a counted pool. */
export function capacityModeLabel(mode: string): string {
    const labels: Record<string, string> = {
        unit: trans('Unit'),
        quantity: trans('Quantity'),
    };

    return labels[mode] ?? mode;
}
