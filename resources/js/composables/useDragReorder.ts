import type { Ref } from 'vue';
import { ref } from 'vue';

/**
 * Native HTML5 drag-and-drop reordering with keyboard parity (ADR-0042),
 * shared by the sidebar favorites, the block composer and the footer editor.
 * The list itself stays wherever the caller keeps it — a form array, a local
 * ref — reached through `get`/`set`; the composable only knows how to move
 * one index to another and when a gesture has settled.
 */
export type DragReorderOptions<T> = {
    get: () => T[];
    set: (next: T[]) => void;
    /** Called once per settled gesture: a drop, a drag ending outside, or an arrow-key move. */
    onSettled?: () => void;
};

export function useDragReorder<T>(options: DragReorderOptions<T>) {
    /** Index of the row currently being dragged, tracked as it moves. */
    const draggingIndex: Ref<number | null> = ref(null);

    function moveLocally(from: number, to: number): boolean {
        const items = options.get();

        if (to < 0 || to >= items.length || from === to) {
            return false;
        }

        const next = [...items];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        options.set(next);

        return true;
    }

    function onDragStart(index: number, event: DragEvent): void {
        draggingIndex.value = index;

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Firefox refuses to start a drag without payload.
            event.dataTransfer.setData('text/plain', String(index));
        }
    }

    function onDragEnter(index: number): void {
        if (draggingIndex.value === null) {
            return;
        }

        if (moveLocally(draggingIndex.value, index)) {
            draggingIndex.value = index;
        }
    }

    function onDrop(): void {
        draggingIndex.value = null;
        options.onSettled?.();
    }

    function onDragEnd(): void {
        if (draggingIndex.value === null) {
            return;
        }

        // Dropped outside the list: keep what the user saw rather than
        // snapping back, so the gesture is never silently lost.
        draggingIndex.value = null;
        options.onSettled?.();
    }

    function onHandleKeydown(index: number, event: KeyboardEvent): void {
        const offset =
            event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;

        if (offset === 0) {
            return;
        }

        event.preventDefault();

        if (moveLocally(index, index + offset)) {
            options.onSettled?.();
        }
    }

    return {
        draggingIndex,
        moveLocally,
        onDragStart,
        onDragEnter,
        onDrop,
        onDragEnd,
        onHandleKeydown,
    };
}
