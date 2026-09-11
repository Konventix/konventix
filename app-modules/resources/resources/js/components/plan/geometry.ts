import type { Bbox, Point } from './transform';

/**
 * Geometry over the plan document (design §5): absolute positions from the
 * parent chain, bounding boxes per shape kind, the hit radius that keeps a
 * place tappable at any zoom, the zone → group → place tree the list view
 * renders, and the three document-level helpers the editor drives its
 * overlay from (`absoluteBbox`, `documentBbox`, `hitTest`).
 *
 * Coordinates are integer millimetres, origin top-left, Y down, each
 * element relative to its parent. `rect` and `polygon` have their origin at
 * the top-left corner, `ellipse` and `point` at the centre. Rotation is
 * degrees, clockwise, about the element's own origin (design §5) — exactly
 * SVG's `rotate()` in this y-down viewBox, so an element's own tilt needs
 * no extra math (`PlanShape.vue` passes `rotation` straight to the SVG
 * attribute). An *ancestor's* rotation is a different question: it moves
 * where a descendant's origin actually lands, so `absoluteOrigin` composes
 * the full chain the way the backend's `Plans\WorldTransform::child()`
 * does, rather than summing `x`/`y` — a naive sum agrees with it only when
 * every ancestor's rotation is zero. Once an origin is resolved, a shape's
 * own bounding box still ignores its own local rotation: these boxes only
 * size hit targets and fit the view, where an unrotated box is good enough
 * and a rotated one costs a matrix per element for no behavioural gain.
 */
type Element = Konventix.Resources.Data.PlanElementData;
type Shape = Konventix.Resources.Data.ShapeData;
type Document = Konventix.Resources.Data.PlanDocumentData;
type Kind = Konventix.Resources.Enums.ElementKind;

/**
 * One Bbox shape for the whole slice, corner coordinates in millimetres:
 * `{ minX, minY, maxX, maxY }`. It lives in transform.ts because the fit
 * math needs it; it is re-exported here so a consumer that only thinks in
 * documents (the editor overlay, S7) imports type and functions from one
 * module. There is no `{ x, y, w, h }` variant anywhere — width is
 * `maxX - minX`.
 */
export type { Bbox } from './transform';

export const DEFAULT_POINT_RADIUS_MM = 250;

/** Deeper than any sane plan; guards the parent-chain walk against a cyclic parent. */
const MAX_DEPTH = 32;

export type PlanGroupNode = { id: string; label: string; places: Element[] };
export type PlanZoneNode = {
    id: string;
    label: string;
    groups: PlanGroupNode[];
};

export function elementIndex(document: Document): Map<string, Element> {
    return new Map(document.elements.map((element) => [element.id, element]));
}

/** `{a c e; b d f}` of the SVG convention `matrix(a b c d e f)`; see `composeChild`. */
type Affine = {
    a: number;
    b: number;
    c: number;
    d: number;
    e: number;
    f: number;
};

const IDENTITY: Affine = { a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 };

/**
 * `transform` followed by a child's `translate(x, y) rotate(deg)`, in
 * lock-step with `Plans\WorldTransform::child()` on the backend — the two
 * must agree, because this is what "the same place" means between the
 * picker's rendering and the backend's neighbour and conflict checks.
 */
function composeChild(
    transform: Affine,
    x: number,
    y: number,
    rotationDegrees: number,
): Affine {
    const rad = (rotationDegrees * Math.PI) / 180;
    const cos = Math.cos(rad);
    const sin = Math.sin(rad);

    return {
        a: transform.a * cos + transform.c * sin,
        b: transform.b * cos + transform.d * sin,
        c: -transform.a * sin + transform.c * cos,
        d: -transform.b * sin + transform.d * cos,
        e: transform.a * x + transform.c * y + transform.e,
        f: transform.b * x + transform.d * y + transform.f,
    };
}

/**
 * The absolute position of `element`'s own origin, composing translation
 * *and rotation* up the parent chain (see the module docblock). A cyclic
 * parent chain is walked at most `MAX_DEPTH` times and then treated as
 * having no further parent, rather than hanging.
 */
export function absoluteOrigin(
    element: Element,
    index: Map<string, Element>,
): Point {
    const chain: Element[] = [element];
    const seen = new Set([element.id]);
    let current = element;
    let depth = 0;

    while (current.parentId !== null && depth < MAX_DEPTH) {
        const parent = index.get(current.parentId);

        if (!parent || seen.has(parent.id)) {
            break;
        }

        chain.push(parent);
        seen.add(parent.id);
        current = parent;
        depth += 1;
    }

    let transform = IDENTITY;

    for (let i = chain.length - 1; i >= 0; i -= 1) {
        const node = chain[i];
        transform = composeChild(transform, node.x, node.y, node.rotation);
    }

    return { x: transform.e, y: transform.f };
}

export function shapeBbox(shape: Shape, origin: Point): Bbox {
    switch (shape.type) {
        case 'rect':
            return {
                minX: origin.x,
                minY: origin.y,
                maxX: origin.x + (shape.w ?? 0),
                maxY: origin.y + (shape.h ?? 0),
            };
        case 'ellipse': {
            const rx = shape.rx ?? 0;
            const ry = shape.ry ?? 0;

            return {
                minX: origin.x - rx,
                minY: origin.y - ry,
                maxX: origin.x + rx,
                maxY: origin.y + ry,
            };
        }
        case 'point': {
            const r = shape.radius ?? DEFAULT_POINT_RADIUS_MM;

            return {
                minX: origin.x - r,
                minY: origin.y - r,
                maxX: origin.x + r,
                maxY: origin.y + r,
            };
        }
        case 'polygon': {
            const points = shape.points ?? [];

            if (points.length === 0) {
                return {
                    minX: origin.x,
                    minY: origin.y,
                    maxX: origin.x,
                    maxY: origin.y,
                };
            }

            const xs = points.map((point) => origin.x + point[0]);
            const ys = points.map((point) => origin.y + point[1]);

            return {
                minX: Math.min(...xs),
                minY: Math.min(...ys),
                maxX: Math.max(...xs),
                maxY: Math.max(...ys),
            };
        }
    }
}

export function shapeCenter(shape: Shape, origin: Point): Point {
    const box = shapeBbox(shape, origin);

    return { x: (box.minX + box.maxX) / 2, y: (box.minY + box.maxY) / 2 };
}

export function polygonPoints(shape: Shape, origin: Point): string {
    return (shape.points ?? [])
        .map((point) => `${origin.x + point[0]},${origin.y + point[1]}`)
        .join(' ');
}

export function elementBbox(
    element: Element,
    index: Map<string, Element>,
): Bbox {
    return shapeBbox(element.shape, absoluteOrigin(element, index));
}

export function unionBbox(a: Bbox, b: Bbox): Bbox {
    return {
        minX: Math.min(a.minX, b.minX),
        minY: Math.min(a.minY, b.minY),
        maxX: Math.max(a.maxX, b.maxX),
        maxY: Math.max(a.maxY, b.maxY),
    };
}

export function contentBbox(document: Document): Bbox | null {
    const index = elementIndex(document);
    let box: Bbox | null = null;

    for (const element of document.elements) {
        const own = elementBbox(element, index);
        box = box === null ? own : unionBbox(box, own);
    }

    return box;
}

/**
 * The absolute box of one element by id — what the editor rings when it is
 * selected and offsets while it is dragged. An id that is not in the
 * document yields an empty box at the origin rather than throwing: the
 * store can hand over a selection whose element a concurrent mutation just
 * removed, and an overlay rectangle of zero size is the right answer.
 */
export function absoluteBbox(document: Document, elementId: string): Bbox {
    const index = elementIndex(document);
    const element = index.get(elementId);

    return element
        ? elementBbox(element, index)
        : { minX: 0, minY: 0, maxX: 0, maxY: 0 };
}

/** The whole document's extent, `null` when it holds no element (alias of `contentBbox` for the editor's vocabulary). */
export function documentBbox(document: Document): Bbox | null {
    return contentBbox(document);
}

/**
 * The topmost element whose box contains `point`, or `null` for the floor.
 * Reverse document order, so a place drawn over its zone wins; a `point`
 * shape is padded to `DEFAULT_POINT_RADIUS_MM` so a zero-size element is
 * still grabbable. The editor calls this once per pointer-down instead of
 * putting a listener on every seat (design §8.3).
 */
export function hitTest(document: Document, point: Point): string | null {
    const index = elementIndex(document);

    for (let i = document.elements.length - 1; i >= 0; i -= 1) {
        const element = document.elements[i];
        const box = elementBbox(element, index);
        const pad =
            element.shape.type === 'point' ? DEFAULT_POINT_RADIUS_MM : 0;

        if (
            point.x >= box.minX - pad &&
            point.x <= box.maxX + pad &&
            point.y >= box.minY - pad &&
            point.y <= box.maxY + pad
        ) {
            return element.id;
        }
    }

    return null;
}

/**
 * Half of a 44 px touch target in world millimetres at the current scale
 * (ADR-0055; design §7.4 "44 px touch targets independent of zoom").
 * `pxPerUnit` is screen pixels per viewBox unit, which the scene measures
 * from its own size; a zero (not yet laid out) falls back to 22 mm.
 */
export function hitRadiusWorld(
    pxPerUnit: number,
    zoom: number,
    minTargetPx = 44,
): number {
    const scale = pxPerUnit * zoom;

    if (scale <= 0) {
        return minTargetPx / 2;
    }

    return minTargetPx / 2 / scale;
}

export function ancestorOfKind(
    element: Element,
    index: Map<string, Element>,
    kind: Kind,
): Element | null {
    let parentId = element.parentId;
    let depth = 0;

    while (parentId !== null && depth < MAX_DEPTH) {
        const parent = index.get(parentId);

        if (!parent) {
            return null;
        }

        if (parent.kind === kind) {
            return parent;
        }

        parentId = parent.parentId;
        depth += 1;
    }

    return null;
}

/** `A-2` before `A-10`: the label schema counts, the string does not. */
export function compareRefs(a: string, b: string): number {
    return a.localeCompare(b, undefined, {
        numeric: true,
        sensitivity: 'base',
    });
}

const LOOSE_ZONE = 'Other places';
const LOOSE_GROUP = 'Ungrouped';

/**
 * Zone → group → place, zones and groups in document order, places by
 * ref. Places outside any zone or group land in trailing "Other places" /
 * "Ungrouped" nodes so the list view never drops a place the scene shows.
 */
export function planTree(document: Document): PlanZoneNode[] {
    const index = elementIndex(document);
    const zones = new Map<string, PlanZoneNode>();

    for (const element of document.elements) {
        if (element.kind === 'zone') {
            zones.set(element.id, {
                id: element.id,
                label: element.label ?? element.ref ?? 'Zone',
                groups: [],
            });
        }
    }

    const looseZone: PlanZoneNode = { id: '', label: LOOSE_ZONE, groups: [] };

    for (const element of document.elements) {
        if (element.kind !== 'place') {
            continue;
        }

        const zone = ancestorOfKind(element, index, 'zone');
        const group = ancestorOfKind(element, index, 'group');
        const zoneNode = zone ? (zones.get(zone.id) ?? looseZone) : looseZone;
        const groupId = group?.id ?? '';
        let groupNode = zoneNode.groups.find(
            (candidate) => candidate.id === groupId,
        );

        if (!groupNode) {
            groupNode = {
                id: groupId,
                label: group
                    ? (group.label ?? group.ref ?? 'Group')
                    : LOOSE_GROUP,
                places: [],
            };
            zoneNode.groups.push(groupNode);
        }

        groupNode.places.push(element);
    }

    const tree = [...zones.values(), looseZone].filter(
        (zone) => zone.groups.length > 0,
    );

    for (const zone of tree) {
        for (const group of zone.groups) {
            group.places.sort((a, b) =>
                compareRefs(a.ref ?? a.label ?? a.id, b.ref ?? b.label ?? b.id),
            );
        }
    }

    return tree;
}
