<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';

/**
 * The title of one programme entry, as every view of the programme prints
 * it: struck when called off (D-AGD-08), and — for a selected competition
 * (§4.5) — marked "Turnier" and linked to the tournament's own page when
 * the builder handed over a `url`. The builder sends one only while
 * Competition is on for the brand, so a missing link is a decision, not a
 * gap to fill here.
 */
type Props = {
    cell: Konventix.Agenda.Data.ProgrammeCellData;
};

const props = defineProps<Props>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <Badge v-if="props.cell.kind === 'competition'" variant="outline">
            Turnier
        </Badge>
        <Link
            v-if="props.cell.url"
            :href="props.cell.url"
            class="font-medium text-text underline-offset-4 hover:underline"
            :class="{ 'line-through': props.cell.cancelled }"
        >
            {{ props.cell.title }}
        </Link>
        <span
            v-else
            class="font-medium text-text"
            :class="{ 'line-through': props.cell.cancelled }"
        >
            {{ props.cell.title }}
        </span>
    </span>
</template>
