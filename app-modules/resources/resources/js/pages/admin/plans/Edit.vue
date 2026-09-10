<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Save, ShieldCheck } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Button } from '@/components/ui/button';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import {
    index as plansIndex,
    occupancy as planOccupancy,
} from '@/routes/admin/plans';
import { nextGroupLabel } from '../../../components/plan/editor/document';
import { checkInvariants } from '../../../components/plan/editor/invariants';
import PlanCanvas from '../../../components/plan/editor/PlanCanvas.vue';
import PlanConflictDialog from '../../../components/plan/editor/PlanConflictDialog.vue';
import PlanCreateGroupDialog from '../../../components/plan/editor/PlanCreateGroupDialog.vue';
import PlanEditorToolbar from '../../../components/plan/editor/PlanEditorToolbar.vue';
import PlanElementTree from '../../../components/plan/editor/PlanElementTree.vue';
import PlanInspector from '../../../components/plan/editor/PlanInspector.vue';
import PlanSavePill from '../../../components/plan/editor/PlanSavePill.vue';
import PlanValidationPanel from '../../../components/plan/editor/PlanValidationPanel.vue';
import type { SnapStep } from '../../../components/plan/editor/snap';
import { usePlanEditorActions } from '../../../components/plan/editor/usePlanEditorActions';
import { usePlanSave } from '../../../components/plan/editor/usePlanSave';
import { usePlanShortcuts } from '../../../components/plan/editor/usePlanShortcuts';
import { createPlanStore } from '../../../components/plan/editor/usePlanStore';

/**
 * The plan editor (design §8.3): tree, canvas, inspector and checks around
 * one store. The page owns what needs the whole picture — the save and its
 * conflict dialog, the unsaved-changes guard (ADR-0038), wiring the
 * shortcuts and assembling the panels — and nothing else; every panel is
 * its own component, and the element-creation/group actions live in
 * `usePlanEditorActions.ts` so this file stays under the house's
 * ~300-line ceiling.
 */
type PlaceState = Konventix.Resources.Enums.PlaceState;
type Occupancy = Konventix.Resources.Data.PlanOccupancyData;

const props = defineProps<{
    editor: Konventix.Resources.Data.PlanEditorData;
}>();

const store = createPlanStore(props.editor.plan.document);
const saver = usePlanSave(props.editor.plan.id, props.editor.version, store);
const canvas = ref<InstanceType<typeof PlanCanvas> | null>(null);
const snapStep = ref<SnapStep>(props.editor.snapStep as SnapStep);
const occupancy = ref<Occupancy>(props.editor.occupancy);
const createGroupOpen = ref(false);

const { createAt, createGroup, regenerate, deleteSelection, selectAndReveal } =
    usePlanEditorActions(store, props.editor.categories, (bbox) =>
        canvas.value?.focusBbox(bbox),
    );

useUnsavedChanges(() => store.isDirty.value);

const categoryIds = computed(() =>
    props.editor.categories.map((category) => category.id),
);
const issues = computed(() =>
    checkInvariants(store.document.value, categoryIds.value),
);
const conflictDialogOpen = computed(() => saver.saveConflicts.value.length > 0);
const canSave = computed(
    () => store.isDirty.value && !saver.busy.value && issues.value.length === 0,
);

const states = computed<Record<string, PlaceState>>(() => {
    const out: Record<string, PlaceState> = {};

    for (const [id, entry] of Object.entries(occupancy.value.places)) {
        out[id] = entry.state as PlaceState;
    }

    for (const element of store.document.value.elements) {
        if (element.kind === 'place' && element.blocked) {
            out[element.id] = 'unavailable';
        }
    }

    return out;
});

const occupancyHttp = useHttp<Record<string, never>, Occupancy>({});

function refreshOccupancy(): void {
    occupancyHttp
        .get(planOccupancy(props.editor.plan.id).url, {
            onSuccess: (result) => {
                occupancy.value = result;
            },
        })
        .catch(() => undefined);
}

usePlanShortcuts({
    store,
    save: () => canSave.value && saver.save(),
    deleteSelection,
});

onMounted(() => canvas.value?.fitAll());
</script>

<template>
    <Head :title="`${props.editor.plan.name} — Plan`" />

    <div class="flex h-[calc(100vh-11rem)] min-h-[36rem] flex-col gap-4">
        <AdminPageHeader
            :title="props.editor.plan.name"
            :description="`${props.editor.plan.widthMm / 1000} × ${props.editor.plan.heightMm / 1000} m · version ${saver.version.value}${props.editor.publishedAt ? ' · published' : ''}`"
        >
            <PlanSavePill
                :status="saver.status.value"
                :message="saver.message.value"
            />
            <Button
                type="button"
                variant="outline"
                :disabled="props.editor.categories.length === 0"
                @click="createGroupOpen = true"
            >
                <Plus class="size-4" />
                Add group
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="saver.busy.value"
                @click="saver.validate()"
            >
                <ShieldCheck class="size-4" />
                Check
            </Button>
            <Button type="button" :disabled="!canSave" @click="saver.save()">
                <Save class="size-4" />
                Save
            </Button>
            <Button variant="outline" as-child>
                <Link :href="plansIndex().url">
                    <ArrowLeft class="size-4" />
                    All plans
                </Link>
            </Button>
        </AdminPageHeader>

        <div
            class="grid min-h-0 flex-1 gap-3 lg:grid-cols-[16rem_minmax(0,1fr)_20rem]"
        >
            <aside
                class="min-h-0 overflow-hidden rounded-md border border-border bg-surface"
                aria-label="Elements"
            >
                <PlanElementTree :store="store" />
            </aside>

            <div class="flex min-h-0 flex-col gap-3">
                <PlanEditorToolbar
                    :store="store"
                    :snap-step="snapStep"
                    @update:snap-step="(step) => (snapStep = step)"
                    @fit="canvas?.fitAll()"
                />
                <div
                    class="min-h-0 flex-1 overflow-hidden rounded-md border border-border bg-surface"
                >
                    <PlanCanvas
                        ref="canvas"
                        :store="store"
                        :plan="props.editor.plan"
                        :categories="props.editor.categories"
                        :states="states"
                        :snap-step="snapStep"
                        @canvas-click="createAt"
                    />
                </div>
                <PlanValidationPanel
                    :issues="issues"
                    :conflicts="saver.checkConflicts.value"
                    :warnings="saver.warnings.value"
                    :server-errors="saver.serverErrors.value"
                    @select="selectAndReveal"
                />
            </div>

            <aside
                class="min-h-0 overflow-y-auto rounded-md border border-border bg-surface"
                aria-label="Inspector"
            >
                <PlanInspector
                    :store="store"
                    :plan-id="props.editor.plan.id"
                    :categories="props.editor.categories"
                    :occupancy="occupancy"
                    :can-reserve="props.editor.canReserve"
                    @regenerate="regenerate"
                    @occupancy-changed="refreshOccupancy"
                />
            </aside>
        </div>

        <PlanConflictDialog
            :open="conflictDialogOpen"
            :conflicts="saver.saveConflicts.value"
            :processing="saver.busy.value"
            @update:open="(open) => !open && saver.dismissConflicts()"
            @resolve="(resolutions) => saver.save(resolutions)"
        />
        <PlanCreateGroupDialog
            v-model:open="createGroupOpen"
            :categories="props.editor.categories"
            :suggested-label="nextGroupLabel(store.document.value)"
            @create="createGroup"
        />
    </div>
</template>
