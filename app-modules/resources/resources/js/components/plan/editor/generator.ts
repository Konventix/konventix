import type {
    DraftElement,
    LabelSchema,
    Layout,
    PlanElement,
    Shape,
} from './document';
import { clone, newElement } from './document';

/**
 * The group generator (design §5): a linear row or a grid of places from a
 * layout and a label schema. Regeneration is the same function run over
 * the group's existing children — non-detached places keep their ids and
 * whatever the operator changed on them, get their position and labels
 * rewritten, and the count decides what is appended or dropped at the end.
 * Detached places (moved by hand) are left alone and returned after the
 * generated ones.
 */
export type LayoutSlot = { x: number; y: number; row: number; col: number };

export type GeneratorSeed = {
    parentId: string;
    groupLabel: string;
    layout: Layout;
    labelSchema: LabelSchema;
    resourceTypeId: string;
    shape: Shape;
    existing: readonly PlanElement[];
    newId: () => string;
};

export function renderLabel(
    schema: string,
    vars: { row: string; n: number; col: number },
): string {
    return schema.replace(
        /\{(row|n|col)(?::0(\d))?\}/g,
        (_match: string, name: string, pad?: string): string => {
            const value =
                name === 'row'
                    ? vars.row
                    : String(name === 'n' ? vars.n : vars.col);

            return pad === undefined ? value : value.padStart(Number(pad), '0');
        },
    );
}

export function layoutSlots(layout: Layout): LayoutSlot[] {
    const slots: LayoutSlot[] = [];

    if (layout.type === 'linear') {
        const count = Math.max(0, layout.count ?? 0);

        for (let index = 0; index < count; index++) {
            slots.push({
                x: index * layout.spacing,
                y: 0,
                row: 1,
                col: index + 1,
            });
        }

        return slots;
    }

    const rows = Math.max(0, layout.rows ?? 0);
    const cols = Math.max(0, layout.cols ?? 0);
    const rowSpacing = layout.rowSpacing ?? layout.spacing;

    for (let row = 0; row < rows; row++) {
        for (let col = 0; col < cols; col++) {
            slots.push({
                x: col * layout.spacing,
                y: row * rowSpacing,
                row: row + 1,
                col: col + 1,
            });
        }
    }

    return slots;
}

export function generatePlaces(seed: GeneratorSeed): DraftElement[] {
    const slots = layoutSlots(seed.layout);
    const attached = seed.existing.filter(
        (element) => element.kind === 'place' && !element.detached,
    );
    const untouched = seed.existing.filter(
        (element) => element.kind !== 'place' || element.detached,
    );

    const generated = slots.map((slot, index): DraftElement => {
        const rowLabel = renderLabel(seed.labelSchema.row, {
            row: seed.groupLabel,
            n: slot.row,
            col: slot.col,
        });
        const label = renderLabel(seed.labelSchema.place, {
            row: rowLabel,
            n: seed.layout.startIndex + index,
            col: slot.col,
        });

        const base: DraftElement = attached[index]
            ? (clone(attached[index]) as DraftElement)
            : newElement('place', seed.newId(), {
                  parentId: seed.parentId,
                  resourceTypeId: seed.resourceTypeId,
                  shape: clone(seed.shape),
              });

        return { ...base, x: slot.x, y: slot.y, label, ref: label };
    });

    return [
        ...generated,
        ...untouched.map((element) => clone(element) as DraftElement),
    ];
}
