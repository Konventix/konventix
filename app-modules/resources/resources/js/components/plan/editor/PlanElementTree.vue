<script setup lang="ts">
import { CircleAlert, Lock } from '@lucide/vue';
import { computed } from 'vue';
import type { PlanElement } from './document';
import { childrenIndex } from './document';
import type { PlanStore } from './usePlanStore';

/**
 * The second navigation next to the canvas (LanCore lesson: a container has
 * no reliable hit area, so the list is how you reach a group or a zone).
 * Buttons with `aria-pressed` carry the selection, so the whole tree is a
 * keyboard path (.ai/rules/js.md).
 */
const props = defineProps<{ store: PlanStore }>();

const store = props.store;

type Row = { element: PlanElement; depth: number };

const rows = computed<Row[]>(() => {
    const index = childrenIndex(store.document.value);
    const out: Row[] = [];

    const walk = (parentId: string | null, depth: number): void => {
        for (const element of index.get(parentId) ?? []) {
            out.push({ element, depth });
            walk(element.id, depth + 1);
        }
    };

    walk(null, 0);

    return out;
});

function title(element: PlanElement): string {
    return element.label ?? element.ref ?? element.text ?? element.kind;
}

function onClick(element: PlanElement, event: MouseEvent): void {
    if (event.shiftKey) {
        store.toggleSelected(element.id);
    } else {
        store.select([element.id]);
    }
}
</script>

<template>
    <nav
        aria-label="Plan elements"
        class="flex h-full flex-col overflow-y-auto"
    >
        <p v-if="rows.length === 0" class="p-3 text-sm text-text-muted">
            No elements yet. Add a group, or press S and click the floor to
            place a seat.
        </p>
        <ul v-else class="flex flex-col py-1">
            <li v-for="row in rows" :key="row.element.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 py-1 pr-2 text-left text-sm text-text hover:bg-subtle aria-pressed:bg-accent/15"
                    :style="{ paddingLeft: `${0.5 + row.depth}rem` }"
                    :aria-pressed="
                        store.selection.value.includes(row.element.id)
                    "
                    @click="onClick(row.element, $event)"
                >
                    <span
                        class="w-16 shrink-0 text-xs text-text-muted uppercase"
                        >{{ row.element.kind }}</span
                    >
                    <span class="truncate">{{ title(row.element) }}</span>
                    <Lock
                        v-if="row.element.locked"
                        class="ml-auto size-3 shrink-0 text-text-muted"
                        aria-label="Locked"
                    />
                    <CircleAlert
                        v-if="row.element.blocked"
                        class="size-3 shrink-0 text-warning"
                        aria-label="Blocked"
                    />
                </button>
            </li>
        </ul>
    </nav>
</template>
