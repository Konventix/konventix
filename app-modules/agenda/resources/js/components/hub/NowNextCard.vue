<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { formatTime } from '@/lib/datetime';

/**
 * The live hub's "Jetzt / Gleich" card (design §6): the two questions a
 * participant standing in a hall actually has — what is running now, what
 * starts next — and the way to the whole programme.
 *
 * The props are `NowNextCardData`'s fields, spread onto this component by
 * the hub shell (`v-bind="card.payload"`) — the shell never looks inside a
 * payload, which is what lets Platform render a card it has never heard of
 * (ADR-0003), so the interface below is the whole contract.
 *
 * Imports nothing of another module's, which
 * `tests/Feature/HubComponentBoundaryTest.php` enforces: the URL arrives in
 * the payload, so the card needs no route helper.
 *
 * `startsAt` is a wall clock in the *event's* zone (ADR-0027), `HH:MM`, and
 * `formatTime()` is the right thing to put it through and the safe one —
 * given a bare `HH:MM` it writes the digits out as they came, changing only
 * the operator's 12/24-hour choice, because a wall clock has no date to
 * hang an offset on. It does not convert, which is exactly what must not
 * happen here.
 *
 * Either entry may be a selected competition (§4.5): it carries a small
 * "Turnier" mark and, when the payload has a `url` for it, links to the
 * tournament's page — which `NowNext` only sends while Competition is on
 * for the brand. The mark and link are written out here rather than taken
 * from the programme's `ProgrammeCellTitle`, because a hub component
 * imports only `@/components/ui/*`, `@/lib/*` and generated types.
 *
 * Both entries are independently null and the card says so rather than
 * hiding: before the first item of the day there is only a "Gleich", after
 * the last one there is neither, and a participant looking at an empty card
 * has been told something true.
 */
// Spelled out rather than `Konventix.Agenda.Data.NowNextCardData`: a bare
// namespaced type fails the SFC compiler's macro type resolver (see
// `admission/tickets/Show.vue`). Mirrors `NowNextCardData` field for field,
// including the ones this card does not print, so the shell's
// `v-bind="card.payload"` has nothing left to fall through onto the root
// element as an attribute.
type Props = {
    now: Konventix.Agenda.Data.ProgrammeCellData | null;
    next: Konventix.Agenda.Data.ProgrammeCellData | null;
    programmeUrl: string;
};

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <p class="text-xs font-semibold tracking-wide text-text-muted">
                Jetzt
            </p>
            <template v-if="props.now">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Badge
                        v-if="props.now.kind === 'competition'"
                        variant="outline"
                    >
                        Turnier
                    </Badge>
                    <Link
                        v-if="props.now.url"
                        :href="props.now.url"
                        class="font-medium text-text underline-offset-4 hover:underline"
                    >
                        {{ props.now.title }}
                    </Link>
                    <span v-else class="font-medium text-text">
                        {{ props.now.title }}
                    </span>
                </p>
                <p class="text-sm text-text-muted">
                    <span>{{ formatTime(props.now.startsAt) }}</span>
                    <span v-if="props.now.endsAt">
                        – {{ formatTime(props.now.endsAt) }}</span
                    >
                    <span v-if="props.now.location">
                        · {{ props.now.location }}</span
                    >
                </p>
            </template>
            <p v-else class="text-sm text-text-muted">Gerade läuft nichts</p>
        </div>

        <div class="flex flex-col gap-1">
            <p class="text-xs font-semibold tracking-wide text-text-muted">
                Gleich
            </p>
            <template v-if="props.next">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Badge
                        v-if="props.next.kind === 'competition'"
                        variant="outline"
                    >
                        Turnier
                    </Badge>
                    <Link
                        v-if="props.next.url"
                        :href="props.next.url"
                        class="font-medium text-text underline-offset-4 hover:underline"
                    >
                        {{ props.next.title }}
                    </Link>
                    <span v-else class="font-medium text-text">
                        {{ props.next.title }}
                    </span>
                </p>
                <p class="text-sm text-text-muted">
                    <span>{{ formatTime(props.next.startsAt) }}</span>
                    <span v-if="props.next.endsAt">
                        – {{ formatTime(props.next.endsAt) }}</span
                    >
                    <span v-if="props.next.location">
                        · {{ props.next.location }}</span
                    >
                </p>
            </template>
            <p v-else class="text-sm text-text-muted">Danach nichts mehr</p>
        </div>

        <!-- No slug, no public page to point at: a wrong link on a card
             people follow in a hall is worse than no link. -->
        <Link
            v-if="props.programmeUrl"
            :href="props.programmeUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            Ganzes Programm
        </Link>
    </div>
</template>
