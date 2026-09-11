import type { Bbox } from '../geometry';
import { elementById } from './document';
import type { Point } from './document';
import type { GroupSpec } from './planEditing';
import {
    annotationAt,
    buildGroup,
    placeAt,
    regeneratedChildren,
} from './planEditing';
import { newUlid } from './ulid';
import type { PlanStore } from './usePlanStore';

/**
 * The page-level actions that turn a canvas click or a dialog submit into
 * document mutations: where a new place or annotation lands (the single
 * selected container, else the root), creating a group, regenerating one,
 * and the two housekeeping actions the shortcuts and the validation panel
 * call into (delete the selection, select-and-reveal an id). Split out of
 * `Edit.vue` to keep the page itself under the house's ~300-line ceiling —
 * the same reason `SeatPlanScene.vue` grew `geometry.ts` and
 * `useScenePointer.ts` beside it instead of carrying their logic inline.
 */
export function usePlanEditorActions(
    store: PlanStore,
    categories: Konventix.Resources.Data.CategoryData[],
    onGroupCreated: (bbox: Bbox) => void,
) {
    /** The parent a new element gets: the single selected container, else the root. */
    function targetParent(): string | null {
        const single =
            store.selectedElements.value.length === 1
                ? store.selectedElements.value[0]
                : null;

        if (single && (single.kind === 'group' || single.kind === 'zone')) {
            return single.id;
        }

        if (single?.parentId) {
            return single.parentId;
        }

        return null;
    }

    function createAt(point: Point): void {
        const parentId = targetParent();

        if (store.tool.value === 'annotation') {
            const annotation = annotationAt(
                store.document.value,
                point,
                parentId,
                newUlid,
            );
            store.addElements('add-annotation', [annotation]);
            store.select([annotation.id]);

            return;
        }

        const category = categories[0];

        if (!category) {
            return;
        }

        const place = placeAt(
            store.document.value,
            point,
            parentId,
            category.id,
            newUlid,
        );
        store.addElements('add-place', [place]);
        store.select([place.id]);
    }

    function createGroup(spec: GroupSpec): void {
        const { elements, bbox } = buildGroup(
            store.document.value,
            spec,
            newUlid,
        );
        store.addElements('add-group', elements);
        store.select([elements[0].id]);
        store.tool.value = 'select';
        onGroupCreated(bbox);
    }

    function regenerate(groupId: string): void {
        const children = regeneratedChildren(
            store.document.value,
            groupId,
            newUlid,
        );

        if (!children) {
            return;
        }

        store.applyMutation(
            'regenerate',
            (draft) => {
                draft.elements = [
                    ...draft.elements.filter(
                        (element) => element.parentId !== groupId,
                    ),
                    ...children,
                ];
            },
            false,
        );
        store.select([groupId]);
    }

    function deleteSelection(): void {
        if (store.selection.value.length > 0) {
            store.removeElements(store.selection.value);
        }
    }

    function selectAndReveal(id: string): void {
        if (elementById(store.document.value, id)) {
            store.select([id]);
        }
    }

    return {
        createAt,
        createGroup,
        regenerate,
        deleteSelection,
        selectAndReveal,
    };
}
