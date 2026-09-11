<script setup lang="ts">
/**
 * The category legend above the plan (design §7.4): every category of the
 * event, dimmed when it is not one of the claim's own — the name carries
 * the distinction, colour is never the only cue (ADR-0055). Split out of
 * `Pick.vue` purely to keep the page near the project's size guideline.
 */
type Category = Konventix.Resources.Data.CategoryData;

defineProps<{
    categories: Category[];
    claimResourceTypeIds: string[];
}>();
</script>

<template>
    <ul
        class="flex flex-wrap gap-3 text-xs text-text-muted"
        aria-label="Categories"
    >
        <li
            v-for="category in categories"
            :key="category.id"
            class="inline-flex items-center gap-1"
        >
            <span
                class="inline-block size-3 rounded-sm"
                :class="
                    claimResourceTypeIds.includes(category.id)
                        ? ''
                        : 'opacity-40'
                "
                :style="{ background: `var(--${category.token})` }"
                aria-hidden="true"
            />
            {{ category.name
            }}<template v-if="!claimResourceTypeIds.includes(category.id)">
                (not yours)</template
            >
        </li>
    </ul>
</template>
