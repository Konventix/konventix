<script setup lang="ts">
/**
 * One tab per published plan (design §7.4). Roving tabindex with arrow
 * keys, Home and End — the tab list is the first thing a keyboard user
 * meets on the page, and a plan that cannot be reached cannot be chosen
 * from.
 */
type Plan = Konventix.Resources.Data.PlanViewData;

const props = defineProps<{
    plans: Plan[];
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [id: string];
}>();

function select(id: string): void {
    emit('update:modelValue', id);
}

function onKeydown(event: KeyboardEvent, index: number): void {
    const last = props.plans.length - 1;
    const next: Record<string, number> = {
        ArrowRight: index === last ? 0 : index + 1,
        ArrowLeft: index === 0 ? last : index - 1,
        Home: 0,
        End: last,
    };

    if (!(event.key in next)) {
        return;
    }

    event.preventDefault();
    const target = props.plans[next[event.key]];
    select(target.id);
    (event.currentTarget as HTMLElement).parentElement
        ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
        [next[event.key]]?.focus();
}
</script>

<template>
    <div
        role="tablist"
        aria-label="Plans"
        class="flex flex-wrap gap-1 border-b border-border"
    >
        <button
            v-for="(plan, index) in plans"
            :id="`plan-tab-${plan.id}`"
            :key="plan.id"
            type="button"
            role="tab"
            :aria-selected="plan.id === modelValue"
            :aria-controls="`plan-panel-${plan.id}`"
            :tabindex="plan.id === modelValue ? 0 : -1"
            class="-mb-px min-h-11 border-b-2 px-3 text-sm aria-selected:border-accent aria-selected:font-medium aria-selected:text-text"
            :class="
                plan.id === modelValue
                    ? ''
                    : 'border-transparent text-text-muted hover:text-text'
            "
            @click="select(plan.id)"
            @keydown="onKeydown($event, index)"
        >
            {{ plan.name
            }}<template v-if="plan.floorLabel">
                · {{ plan.floorLabel }}</template
            >
        </button>
    </div>
</template>
