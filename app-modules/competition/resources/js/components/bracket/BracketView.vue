<script setup lang="ts">
import { onMounted, ref } from 'vue';
import BracketCanvas from './BracketCanvas.vue';
import BracketTable from './BracketTable.vue';

/**
 * The bracket, as a picture or as a table (design §10): "the table view of
 * the same rounds is the keyboard- and screen-reader-accessible alternative
 * (ADR-0055) and is the default on narrow viewports".
 *
 * The table is what the server renders, so a phone, a screen reader and a
 * page without JavaScript all start on it; a wide screen switches to the
 * canvas once mounted. The switch is two toggle buttons with `aria-pressed`,
 * both reachable by keyboard, and the choice is the reader's from then on.
 */
const props = defineProps<{
    bracket: Konventix.Competition.Data.BracketData;
    eventSlug: string;
    competitionSlug: string;
    eventTimezone: string | null;
}>();

const view = ref<'table' | 'canvas'>('table');

onMounted(() => {
    if (window.matchMedia('(min-width: 768px)').matches) {
        view.value = 'canvas';
    }
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex gap-1" role="group" aria-label="Darstellung">
            <button
                type="button"
                class="rounded-md border border-border px-3 py-1 text-sm"
                :class="
                    view === 'canvas'
                        ? 'bg-accent text-on-accent'
                        : 'bg-surface text-text'
                "
                :aria-pressed="view === 'canvas'"
                @click="view = 'canvas'"
            >
                Turnierbaum
            </button>
            <button
                type="button"
                class="rounded-md border border-border px-3 py-1 text-sm"
                :class="
                    view === 'table'
                        ? 'bg-accent text-on-accent'
                        : 'bg-surface text-text'
                "
                :aria-pressed="view === 'table'"
                @click="view = 'table'"
            >
                Tabelle
            </button>
        </div>

        <BracketCanvas
            v-if="view === 'canvas'"
            :bracket="props.bracket"
            :event-slug="props.eventSlug"
            :competition-slug="props.competitionSlug"
        />
        <BracketTable
            v-else
            :bracket="props.bracket"
            :event-slug="props.eventSlug"
            :competition-slug="props.competitionSlug"
            :event-timezone="props.eventTimezone"
        />
    </div>
</template>
