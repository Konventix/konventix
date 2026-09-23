<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, GripVertical } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    generate as generateStage,
    regenerate as regenerateStage,
    reset as resetStage,
    seed as seedStage,
    shuffle as shuffleStage,
} from '@/routes/admin/competition-stages';
import ConfirmDialog from '../../confirm/ConfirmDialog.vue';
import { useConfirmation } from '../../confirm/useConfirmation';

/**
 * Seed order and the draw (design §7.5, D-CMP-20).
 *
 * The list is the field in seed order — the same order `GenerateBracket`
 * draws from — and the desk reorders it by dragging or, from the keyboard,
 * with the up and down buttons on every row (a drag alone would leave the
 * order unreachable without a mouse, .ai/rules/js.md). Nothing is written
 * until "Save order"; "Shuffle" asks the server for a fresh random order.
 *
 * Generate draws an undrawn stage. Once drawn, "Draw again" redraws from the
 * current seed order and "Reset" takes it back to undrawn. All of it stops at
 * the first recorded result, which the server enforces and the panel says.
 */
type Entrant = Konventix.Competition.Data.DeskEntrantData;

const props = defineProps<{
    stage: Konventix.Competition.Data.DeskStageData;
    field: Entrant[];
    canManage: boolean;
}>();

const order = ref<Entrant[]>([...props.field]);

watch(
    () => props.field,
    (field) => (order.value = [...field]),
);

const dirty = computed(
    () =>
        order.value.map((entrant) => entrant.id).join() !==
        props.field.map((entrant) => entrant.id).join(),
);

const locked = computed(() => !props.canManage || props.stage.hasResult);

const dragging = ref<number | null>(null);

function move(from: number, to: number): void {
    if (to < 0 || to >= order.value.length || from === to) {
        return;
    }

    const next = [...order.value];
    const [entrant] = next.splice(from, 1);
    next.splice(to, 0, entrant);
    order.value = next;
}

function drop(to: number): void {
    if (dragging.value !== null) {
        move(dragging.value, to);
    }

    dragging.value = null;
}

const options = { preserveScroll: true };

function save(): void {
    router.post(
        seedStage(props.stage.id).url,
        { entrantIds: order.value.map((entrant) => entrant.id) },
        options,
    );
}

/**
 * Redrawing and resetting throw the current draw away, so both ask first —
 * on the dialog primitive, not `window.confirm` (final review, part 3, I2).
 */
const {
    pending: pendingQuestion,
    open: asking,
    ask,
    confirm,
} = useConfirmation();

function drawAgain(): void {
    ask({
        title: 'Draw the bracket again?',
        description:
            'Every match of this bracket is drawn anew from the current seed order.',
        confirmLabel: 'Draw again',
        cancelLabel: 'Keep the draw',
        run: () =>
            router.post(regenerateStage(props.stage.id).url, {}, options),
    });
}

function reset(): void {
    ask({
        title: 'Reset this bracket?',
        description:
            'Every match of this bracket is deleted and the stage is undrawn again. The seeds are kept.',
        confirmLabel: 'Reset',
        cancelLabel: 'Keep the draw',
        run: () => router.post(resetStage(props.stage.id).url, {}, options),
    });
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4"
        aria-labelledby="desk-seeding"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="desk-seeding" class="text-lg font-medium text-text">
                Seeding
            </h2>
            <p v-if="props.stage.hasResult" class="text-sm text-text-muted">
                A result is recorded; the seeding and the draw are final.
            </p>
        </div>

        <p v-if="order.length === 0" class="text-sm text-text-muted">
            Nobody is in the field yet.
        </p>

        <ol v-else class="flex flex-col gap-1">
            <li
                v-for="(entrant, index) in order"
                :key="entrant.id"
                class="flex items-center gap-2 rounded-md border border-border bg-surface px-2 py-1 text-sm"
                :draggable="!locked"
                @dragstart="dragging = index"
                @dragover.prevent
                @drop.prevent="drop(index)"
            >
                <GripVertical
                    v-if="!locked"
                    class="size-4 text-text-muted"
                    aria-hidden="true"
                />
                <span class="w-8 text-text-muted">{{ index + 1 }}.</span>
                <span class="flex-1 text-text">{{ entrant.teamName }}</span>
                <template v-if="!locked">
                    <Button
                        size="icon"
                        variant="ghost"
                        :disabled="index === 0"
                        @click="move(index, index - 1)"
                    >
                        <ArrowUp class="size-4" />
                        <span class="sr-only"
                            >Move {{ entrant.teamName }} up</span
                        >
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        :disabled="index === order.length - 1"
                        @click="move(index, index + 1)"
                    >
                        <ArrowDown class="size-4" />
                        <span class="sr-only"
                            >Move {{ entrant.teamName }} down</span
                        >
                    </Button>
                </template>
            </li>
        </ol>

        <div v-if="!locked" class="flex flex-wrap gap-2">
            <Button size="sm" :disabled="!dirty" @click="save">
                Save order
            </Button>
            <Button
                size="sm"
                variant="outline"
                @click="
                    router.post(shuffleStage(props.stage.id).url, {}, options)
                "
            >
                Shuffle
            </Button>

            <Button
                v-if="!props.stage.generated"
                size="sm"
                :disabled="dirty"
                @click="
                    router.post(generateStage(props.stage.id).url, {}, options)
                "
            >
                Generate bracket
            </Button>
            <template v-else>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="dirty"
                    @click="drawAgain"
                >
                    Draw again
                </Button>
                <Button size="sm" variant="destructive" @click="reset">
                    Reset
                </Button>
            </template>
        </div>
        <p v-if="dirty" class="text-sm text-warning" role="status">
            Save the order before drawing.
        </p>

        <ConfirmDialog
            v-model:open="asking"
            :confirmation="pendingQuestion"
            @confirm="confirm"
        />
    </section>
</template>
