import type { Bbox } from '../geometry';
import { documentBbox } from '../geometry';
import type {
    DraftElement,
    LabelSchema,
    Layout,
    PlanDocument,
    Point,
} from './document';
import {
    absolutePosition,
    childrenOf,
    DEFAULT_SHAPES,
    elementById,
    newElement,
} from './document';
import { generatePlaces } from './generator';

/**
 * The page-level edits that need more than one element: creating a group
 * with its places, dropping a place or an annotation at a point, and
 * regenerating a group's children. Pure functions over the document; the
 * store applies what they return.
 */
export type GroupSpec = {
    label: string;
    layout: Layout;
    labelSchema: LabelSchema;
    resourceTypeId: string;
};

const GROUP_GAP_MM = 1000;

/**
 * A new group goes right of everything that exists (LanCore lesson: a
 * group created under existing content looks like nothing happened).
 */
export function buildGroup(
    document: PlanDocument,
    spec: GroupSpec,
    newId: () => string,
): { elements: DraftElement[]; bbox: Bbox } {
    // `Bbox` is S5's corner shape `{ minX, minY, maxX, maxY }` (geometry.ts):
    // "right of everything" is `maxX + gap`, and the new group keeps the top
    // edge of what is already there.
    const existing = documentBbox(document);
    const origin: Point = existing
        ? { x: existing.maxX + GROUP_GAP_MM, y: existing.minY }
        : { x: 0, y: 0 };
    const group = newElement('group', newId(), {
        label: spec.label,
        x: origin.x,
        y: origin.y,
        layout: { ...spec.layout },
        labelSchema: { ...spec.labelSchema },
    });

    const places = generatePlaces({
        parentId: group.id,
        groupLabel: spec.label,
        layout: spec.layout,
        labelSchema: spec.labelSchema,
        resourceTypeId: spec.resourceTypeId,
        shape: DEFAULT_SHAPES.place,
        existing: [],
        newId,
    });

    const width = places.reduce(
        (max, place) => Math.max(max, place.x + (place.shape.w ?? 0)),
        0,
    );
    const height = places.reduce(
        (max, place) => Math.max(max, place.y + (place.shape.h ?? 0)),
        0,
    );

    return {
        elements: [group, ...places],
        bbox: {
            minX: origin.x,
            minY: origin.y,
            maxX: origin.x + Math.max(width, 800),
            maxY: origin.y + Math.max(height, 800),
        },
    };
}

/** Coordinates are relative to the parent (design §5). */
function relativeTo(
    document: PlanDocument,
    parentId: string | null,
    point: Point,
): Point {
    if (parentId === null) {
        return point;
    }

    const parent = absolutePosition(document, parentId);

    return { x: point.x - parent.x, y: point.y - parent.y };
}

export function placeAt(
    document: PlanDocument,
    point: Point,
    parentId: string | null,
    resourceTypeId: string,
    newId: () => string,
): DraftElement {
    const position = relativeTo(document, parentId, point);

    return newElement('place', newId(), {
        parentId,
        resourceTypeId,
        detached: parentId !== null,
        x: position.x,
        y: position.y,
    });
}

export function annotationAt(
    document: PlanDocument,
    point: Point,
    parentId: string | null,
    newId: () => string,
): DraftElement {
    const position = relativeTo(document, parentId, point);

    return newElement('annotation', newId(), {
        parentId,
        x: position.x,
        y: position.y,
    });
}

/**
 * The group's children after regeneration, or null when the group has no
 * layout to regenerate from. The caller replaces the old children.
 */
export function regeneratedChildren(
    document: PlanDocument,
    groupId: string,
    newId: () => string,
): DraftElement[] | null {
    const group = elementById(document, groupId);

    if (!group || group.kind !== 'group' || !group.layout) {
        return null;
    }

    const children = childrenOf(document, groupId);
    const firstPlace = children.find(
        (child) => child.kind === 'place' && child.resourceTypeId !== null,
    );

    return generatePlaces({
        parentId: groupId,
        groupLabel: group.label ?? '',
        layout: group.layout,
        labelSchema: group.labelSchema ?? {
            row: '{row}',
            place: '{row}-{n:02}',
        },
        resourceTypeId: firstPlace?.resourceTypeId ?? '',
        shape: firstPlace?.shape ?? DEFAULT_SHAPES.place,
        existing: children,
        newId,
    });
}
