import type { PlanDocument, PlanElement } from './document';
import { ALLOWED_PARENTS } from './document';

type CapacityMode = Konventix.Resources.Enums.CapacityMode;

/**
 * The client mirror of `PlanInvariants::check()` (design §6.1 step 2).
 * Same rules, same keys (`document.elements.<index>.<field>`) as the
 * server's 422, so the validation panel lists both sources alike and the
 * Save button can stay disabled for what the server would refuse anyway.
 *
 * The unit-mode check (item 6 of the 2026-09-10 fix wave, #278) mirrors the
 * server's: `categories` carries every category's capacity mode, not only
 * its id, so a quantity-mode category placed on the plan is refused here
 * too, not only after a round trip to the server.
 */
export type InvariantIssue = {
    index: number;
    elementId: string;
    field: string;
    key: string;
    message: string;
};

type Indexed = { element: PlanElement; index: number };

function hasCycle(byId: Map<string, Indexed>, element: PlanElement): boolean {
    const seen = new Set<string>([element.id]);
    let current = element.parentId;

    while (current !== null) {
        if (seen.has(current)) {
            return true;
        }

        seen.add(current);
        current = byId.get(current)?.element.parentId ?? null;
    }

    return false;
}

export function checkInvariants(
    document: PlanDocument,
    categories: Readonly<Record<string, CapacityMode>>,
): InvariantIssue[] {
    const issues: InvariantIssue[] = [];
    const byId = new Map<string, Indexed>();
    const refs = new Set<string>();

    const report = (
        index: number,
        element: PlanElement,
        field: string,
        message: string,
    ): void => {
        issues.push({
            index,
            elementId: element.id,
            field,
            key: `document.elements.${index}.${field}`,
            message,
        });
    };

    document.elements.forEach((element, index) => {
        if (byId.has(element.id)) {
            report(index, element, 'id', `Id ${element.id} is used twice.`);
        } else {
            byId.set(element.id, { element, index });
        }
    });

    document.elements.forEach((element, index) => {
        if (element.parentId !== null) {
            const parent = byId.get(element.parentId);

            if (!parent) {
                report(
                    index,
                    element,
                    'parentId',
                    'The parent does not exist.',
                );
            } else if (
                !ALLOWED_PARENTS[element.kind].includes(parent.element.kind)
            ) {
                report(
                    index,
                    element,
                    'parentId',
                    `A ${element.kind} cannot sit inside a ${parent.element.kind}.`,
                );
            } else if (hasCycle(byId, element)) {
                report(
                    index,
                    element,
                    'parentId',
                    'The parent chain loops back to this element.',
                );
            }
        }

        if (element.ref !== null && element.ref !== '') {
            if (refs.has(element.ref)) {
                report(
                    index,
                    element,
                    'ref',
                    `Ref ${element.ref} is used twice.`,
                );
            } else {
                refs.add(element.ref);
            }
        }

        if (element.kind === 'place') {
            if (!element.resourceTypeId) {
                report(
                    index,
                    element,
                    'resourceTypeId',
                    'A place needs a category.',
                );
            } else if (!(element.resourceTypeId in categories)) {
                report(
                    index,
                    element,
                    'resourceTypeId',
                    'The category does not belong to this event.',
                );
            } else if (categories[element.resourceTypeId] !== 'unit') {
                report(
                    index,
                    element,
                    'resourceTypeId',
                    'The category must be in unit mode to hold a place.',
                );
            }
        }

        if (
            element.shape.type === 'polygon' &&
            (element.shape.points?.length ?? 0) < 3
        ) {
            report(
                index,
                element,
                'shape.points',
                'A polygon needs at least three points.',
            );
        }
    });

    return issues;
}
