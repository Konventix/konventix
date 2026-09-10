import { onBeforeUnmount, onMounted } from 'vue';
import type { PlanStore, Tool } from './usePlanStore';

/**
 * The editor's keyboard (design §8.3): V select, S place, L annotation,
 * D delete, Space pans while held, Esc clears, Del deletes, Ctrl+Z /
 * Shift+Ctrl+Z undo and redo, Ctrl+S saves. Nothing fires while focus is
 * in a form control, so the inspector keeps its own keys.
 */
const TOOL_KEYS: Record<string, Tool> = {
    v: 'select',
    s: 'place',
    l: 'annotation',
    d: 'delete',
};

function inFormControl(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return (
        element !== null &&
        (['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) ||
            element.isContentEditable)
    );
}

export function usePlanShortcuts(handlers: {
    store: PlanStore;
    save: () => void;
    deleteSelection: () => void;
}): void {
    let toolBeforePan: Tool | null = null;

    function onKeydown(event: KeyboardEvent): void {
        if (inFormControl(event.target)) {
            return;
        }

        const key = event.key.toLowerCase();

        if ((event.ctrlKey || event.metaKey) && key === 'z') {
            event.preventDefault();

            if (event.shiftKey) {
                handlers.store.redo();
            } else {
                handlers.store.undo();
            }

            return;
        }

        if ((event.ctrlKey || event.metaKey) && key === 's') {
            event.preventDefault();
            handlers.save();

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            if (handlers.store.selection.value.length > 0) {
                event.preventDefault();
                handlers.deleteSelection();
            }

            return;
        }

        if (event.key === 'Escape') {
            handlers.store.clearSelection();
            handlers.store.tool.value = 'select';

            return;
        }

        if (event.key === ' ' && toolBeforePan === null) {
            event.preventDefault();
            toolBeforePan = handlers.store.tool.value;
            handlers.store.tool.value = 'pan';

            return;
        }

        const tool = TOOL_KEYS[key];

        if (tool && !event.ctrlKey && !event.metaKey && !event.altKey) {
            handlers.store.tool.value = tool;

            if (tool === 'delete') {
                handlers.store.clearSelection();
            }
        }
    }

    function onKeyup(event: KeyboardEvent): void {
        if (event.key === ' ' && toolBeforePan !== null) {
            handlers.store.tool.value = toolBeforePan;
            toolBeforePan = null;
        }
    }

    onMounted(() => {
        window.addEventListener('keydown', onKeydown);
        window.addEventListener('keyup', onKeyup);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);
        window.removeEventListener('keyup', onKeyup);
    });
}
