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
 *
 * Everything here is phrasing content — the badge renders `as="span"`, not
 * its default div — because the grid and the phone list put this title
 * inside a `<p>`, and a div there is invalid HTML the browser reparents,
 * which breaks SSR hydration on every block.
 *
 * A linked title is underlined at rest, not only on hover: a phone has no
 * hover, and the programme is mostly read on one. The strike of a called-off
 * block sits on an inner span, so it adds to the link's underline instead
 * of replacing it (both set `text-decoration-line`).
 */
type Props = {
    cell: Konventix.Agenda.Data.ProgrammeCellData;
};

const props = defineProps<Props>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <Badge
            v-if="props.cell.kind === 'competition'"
            as="span"
            variant="outline"
        >
            {{ $t('Tournament') }}
        </Badge>
        <Link
            v-if="props.cell.url"
            :href="props.cell.url"
            class="font-medium text-text underline underline-offset-4 hover:text-accent"
        >
            <span :class="{ 'line-through': props.cell.cancelled }">{{
                props.cell.title
            }}</span>
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
