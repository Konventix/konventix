import { computed, ref, shallowRef } from 'vue';
import type {
    DraftDocument,
    DraftElement,
    PlanDocument,
    PlanElement,
} from './document';
import { clone, descendantIds } from './document';

/**
 * Working copy, selection and undo ring of the plan editor (design §8.3).
 *
 * Every edit goes through `applyMutation`, which replaces the document
 * with a mutated clone — the document is never mutated in place, so a
 * snapshot is just the previous object and costs nothing. Mutations with
 * the same label inside `coalesceMs` share one undo step, so typing a
 * label is one step instead of one per keystroke (the LanCore lesson).
 * `isDirty` compares against the last saved document; `markSaved` takes
 * the snapshot that was actually sent, so an edit made while the request
 * was in flight stays unsaved.
 */
export type Tool = 'select' | 'place' | 'annotation' | 'delete' | 'pan';

export type PlanStoreOptions = {
    now?: () => number;
    coalesceMs?: number;
    historyLimit?: number;
};

type Snapshot = { document: DraftDocument; label: string };

function serialise(document: PlanDocument | DraftDocument): string {
    return JSON.stringify(document);
}

export function createPlanStore(
    initial: PlanDocument,
    options: PlanStoreOptions = {},
) {
    const now = options.now ?? ((): number => Date.now());
    const coalesceMs = options.coalesceMs ?? 500;
    const historyLimit = options.historyLimit ?? 50;

    const document = shallowRef<DraftDocument>(clone(initial) as DraftDocument);
    const selection = ref<string[]>([]);
    const tool = ref<Tool>('select');
    const past = shallowRef<Snapshot[]>([]);
    const future = shallowRef<Snapshot[]>([]);
    const baseline = ref(serialise(initial));
    let lastLabel: string | null = null;
    let lastAt = 0;

    const isDirty = computed(
        () => serialise(document.value) !== baseline.value,
    );
    const canUndo = computed(() => past.value.length > 0);
    const canRedo = computed(() => future.value.length > 0);
    const selectedElements = computed<PlanElement[]>(() => {
        const ids = new Set(selection.value);

        return document.value.elements.filter((element) => ids.has(element.id));
    });

    function pruneSelection(): void {
        const ids = new Set(
            document.value.elements.map((element) => element.id),
        );
        selection.value = selection.value.filter((id) => ids.has(id));
    }

    function applyMutation(
        label: string,
        fn: (draft: DraftDocument) => void,
        coalesce = true,
    ): void {
        const at = now();
        const merge =
            coalesce &&
            lastLabel === label &&
            at - lastAt < coalesceMs &&
            past.value.length > 0;

        if (!merge) {
            past.value = [
                ...past.value,
                { document: document.value, label },
            ].slice(-historyLimit);
        }

        future.value = [];

        const draft = clone(document.value);
        fn(draft);
        document.value = draft;
        lastLabel = coalesce ? label : null;
        lastAt = at;
    }

    function undo(): void {
        const snapshot = past.value[past.value.length - 1];

        if (!snapshot) {
            return;
        }

        past.value = past.value.slice(0, -1);
        future.value = [
            ...future.value,
            { document: document.value, label: snapshot.label },
        ];
        document.value = snapshot.document;
        lastLabel = null;
        pruneSelection();
    }

    function redo(): void {
        const snapshot = future.value[future.value.length - 1];

        if (!snapshot) {
            return;
        }

        future.value = future.value.slice(0, -1);
        past.value = [
            ...past.value,
            { document: document.value, label: snapshot.label },
        ];
        document.value = snapshot.document;
        lastLabel = null;
        pruneSelection();
    }

    function markSaved(
        saved: PlanDocument | DraftDocument = document.value,
    ): void {
        baseline.value = serialise(saved);
    }

    function replaceDocument(next: PlanDocument): void {
        document.value = clone(next) as DraftDocument;
        past.value = [];
        future.value = [];
        selection.value = [];
        lastLabel = null;
        markSaved();
    }

    function select(ids: string[]): void {
        selection.value = [...new Set(ids)];
    }

    function toggleSelected(id: string): void {
        selection.value = selection.value.includes(id)
            ? selection.value.filter((selected) => selected !== id)
            : [...selection.value, id];
    }

    function clearSelection(): void {
        selection.value = [];
    }

    function updateElement(
        id: string,
        label: string,
        patch: (element: DraftElement) => void,
    ): void {
        applyMutation(label, (draft) => {
            const element = draft.elements.find(
                (candidate) => candidate.id === id,
            );

            if (element) {
                patch(element);
            }
        });
    }

    function moveElements(ids: string[], dx: number, dy: number): void {
        const moving = new Set(ids);

        applyMutation(
            'move',
            (draft) => {
                for (const element of draft.elements) {
                    if (moving.has(element.id) && !element.locked) {
                        element.x += dx;
                        element.y += dy;
                    }
                }
            },
            false,
        );
    }

    function removeElements(ids: string[]): void {
        const doomed = new Set(ids);

        for (const id of ids) {
            for (const descendant of descendantIds(document.value, id)) {
                doomed.add(descendant);
            }
        }

        applyMutation(
            'delete',
            (draft) => {
                draft.elements = draft.elements.filter(
                    (element) => !doomed.has(element.id),
                );
            },
            false,
        );
        pruneSelection();
    }

    function addElements(label: string, elements: DraftElement[]): void {
        applyMutation(
            label,
            (draft) => {
                draft.elements.push(...elements);
            },
            false,
        );
    }

    return {
        document,
        selection,
        tool,
        isDirty,
        canUndo,
        canRedo,
        selectedElements,
        applyMutation,
        undo,
        redo,
        markSaved,
        replaceDocument,
        select,
        toggleSelected,
        clearSelection,
        updateElement,
        moveElements,
        removeElements,
        addElements,
    };
}

export type PlanStore = ReturnType<typeof createPlanStore>;
