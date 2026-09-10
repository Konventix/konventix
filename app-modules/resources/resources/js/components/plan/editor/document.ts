/**
 * The editor's view of `konventix.plan/v1` (design §5): the generated Data
 * types made writable for drafts, the parent rules, per-kind defaults and
 * the tree walks every panel needs. Pure — no Vue, no DOM — so the
 * generator, the invariants and the store can be unit-tested on their own.
 *
 * Geometry (absolute bounding boxes, hit testing) lives in `../geometry`
 * and is shared with the viewer; this file only knows the tree.
 */
export type PlanDocument = Konventix.Resources.Data.PlanDocumentData;
export type PlanElement = Konventix.Resources.Data.PlanElementData;
export type Shape = Konventix.Resources.Data.ShapeData;
export type Layout = Konventix.Resources.Data.LayoutData;
export type LabelSchema = Konventix.Resources.Data.LabelSchemaData;
export type ElementKind = Konventix.Resources.Enums.ElementKind;

export type Point = { x: number; y: number };

export type Mutable<T> = T extends readonly (infer U)[]
    ? Mutable<U>[]
    : T extends object
      ? { -readonly [K in keyof T]: Mutable<T[K]> }
      : T;

export type DraftDocument = Mutable<PlanDocument>;
export type DraftElement = Mutable<PlanElement>;

/** Allowed parents per kind (design §5); `null` is the plan root. */
export const ALLOWED_PARENTS: Record<
    ElementKind,
    ReadonlyArray<ElementKind | null>
> = {
    zone: [null, 'zone'],
    group: [null, 'zone', 'group'],
    place: ['group', 'zone', null],
    fixture: [null, 'zone', 'group'],
    annotation: [null, 'zone', 'group', 'place', 'fixture', 'annotation'],
};

function shape(type: Shape['type'], dims: Partial<Shape> = {}): Shape {
    return {
        type,
        w: null,
        h: null,
        radius: null,
        rx: null,
        ry: null,
        points: null,
        ...dims,
    };
}

/** Sane defaults from design §5: a LAN place is a table share, 800 × 800. */
export const DEFAULT_SHAPES: Record<ElementKind, Shape> = {
    zone: shape('rect', { w: 4000, h: 4000 }),
    group: shape('point'),
    place: shape('rect', { w: 800, h: 800 }),
    fixture: shape('rect', { w: 1600, h: 800 }),
    annotation: shape('point'),
};

export function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

export function newElement(
    kind: ElementKind,
    id: string,
    overrides: Partial<DraftElement> = {},
): DraftElement {
    return {
        id,
        kind,
        parentId: null,
        ref: null,
        label: null,
        layer: 'default',
        x: 0,
        y: 0,
        rotation: 0,
        shape: clone(DEFAULT_SHAPES[kind]),
        locked: false,
        originId: null,
        token: null,
        layout: null,
        labelSchema: null,
        resourceTypeId: null,
        accessible: false,
        detached: false,
        blocked: false,
        fixtureType: kind === 'fixture' ? 'table' : null,
        text: kind === 'annotation' ? 'Text' : null,
        fontSizeMm: kind === 'annotation' ? 300 : null,
        ...overrides,
    };
}

export function elementById(
    document: PlanDocument,
    id: string,
): PlanElement | undefined {
    return document.elements.find((element) => element.id === id);
}

/** One pass over the flat list; the tree and the inspector walk this. */
export function childrenIndex(
    document: PlanDocument,
): Map<string | null, PlanElement[]> {
    const index = new Map<string | null, PlanElement[]>();

    for (const element of document.elements) {
        const siblings = index.get(element.parentId) ?? [];
        siblings.push(element);
        index.set(element.parentId, siblings);
    }

    return index;
}

export function childrenOf(
    document: PlanDocument,
    parentId: string | null,
): PlanElement[] {
    return document.elements.filter((element) => element.parentId === parentId);
}

export function descendantIds(document: PlanDocument, id: string): string[] {
    const index = childrenIndex(document);
    const out: string[] = [];
    const queue = [id];

    while (queue.length > 0) {
        const current = queue.shift() as string;

        for (const child of index.get(current) ?? []) {
            out.push(child.id);
            queue.push(child.id);
        }
    }

    return out;
}

/**
 * The element's origin in plan millimetres: its own `x, y` plus every
 * ancestor's. Parent rotation is ignored in v1 (recorded in TODO.md).
 */
export function absolutePosition(document: PlanDocument, id: string): Point {
    const byId = new Map(
        document.elements.map((element) => [element.id, element]),
    );
    const seen = new Set<string>();
    let current = byId.get(id);
    const point = { x: 0, y: 0 };

    while (current && !seen.has(current.id)) {
        seen.add(current.id);
        point.x += current.x;
        point.y += current.y;
        current =
            current.parentId === null ? undefined : byId.get(current.parentId);
    }

    return point;
}

/** A, B, … Z, AA, AB — the first label no group carries yet. */
export function nextGroupLabel(document: PlanDocument): string {
    const taken = new Set(
        document.elements
            .filter((element) => element.kind === 'group')
            .map((element) => element.label),
    );

    for (let number = 0; ; number++) {
        let label = '';
        let rest = number;

        do {
            label = String.fromCharCode(65 + (rest % 26)) + label;
            rest = Math.floor(rest / 26) - 1;
        } while (rest >= 0);

        if (!taken.has(label)) {
            return label;
        }
    }
}
