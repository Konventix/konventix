<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import ProgrammeGrid from '../../components/ProgrammeGrid.vue';
import ProgrammeList from '../../components/ProgrammeList.vue';

/**
 * The public programme (design §5, §6): one grid per event day, a day
 * switcher, a track filter. No account, no ticket, no capability — a
 * programme is an announcement.
 *
 * Every time on this page is the organizer's wall clock in the event's own
 * zone (ADR-0027), resolved by `ProgrammeBuilder` before it left the
 * server. The zone is named beside the heading so a visitor reading from
 * somewhere else can tell whose clock they are looking at, and nothing here
 * converts one — see `components/programmeTime.ts`.
 *
 * **The breakpoint is CSS, not JavaScript.** The grid and the list are the
 * same data rendered twice, one hidden at a time (`hidden md:block` /
 * `md:hidden`): no `matchMedia`, nothing to hydrate differently on the
 * server than in the browser, and a phone that reports an odd width still
 * gets one of the two rather than neither.
 *
 * **Which day is preselected is the server's answer.** "Today" means today
 * in the *event's* zone, and the only zone database the browser exposes is
 * `Intl`, which the frontend may not reach for outside `@/lib/datetime`
 * (`.ai/rules/js.md`). `event.today` is that date, computed where the zone
 * is known; a day set that does not contain it falls back to the first day,
 * which is what somebody browsing weeks in advance wants.
 *
 * `days` holds only the days that have something on them — `ProgrammeBuilder`
 * omits an empty one rather than offering the switcher a blank grid.
 */
type Props = {
    event: {
        name: string | null;
        slug: string;
        timezone: string;
        /** Today's date in the event's zone, `YYYY-MM-DD`. */
        today: string;
    };
    days: Konventix.Agenda.Data.ProgrammeDayData[];
};

const props = defineProps<Props>();

const selectedDayId = ref<string>(
    (props.days.find((day) => day.date === props.event.today) ?? props.days[0])
        ?.dayId ?? '',
);

const selectedDay = computed<Konventix.Agenda.Data.ProgrammeDayData | null>(
    () =>
        props.days.find((day) => day.dayId === selectedDayId.value) ??
        props.days[0] ??
        null,
);

/**
 * The filter hides rather than selects, so a track added to the programme
 * after somebody opened the page is visible by default — the opposite would
 * quietly drop it.
 */
const hiddenTracks = ref<string[]>([]);

function toggleTrack(name: string): void {
    hiddenTracks.value = hiddenTracks.value.includes(name)
        ? hiddenTracks.value.filter((hidden) => hidden !== name)
        : [...hiddenTracks.value, name];
}

/** Indices into the day's `trackNames`, which is what both views index by. */
const columns = computed<number[]>(() =>
    (selectedDay.value?.trackNames ?? [])
        .map((name, index) => ({ name, index }))
        .filter(({ name }) => !hiddenTracks.value.includes(name))
        .map(({ index }) => index),
);
</script>

<template>
    <Head :title="`Programm – ${props.event.name ?? ''}`" />

    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6 text-text lg:p-8"
    >
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold">Programm</h1>
            <p class="text-sm text-text-muted">
                <span v-if="props.event.name">{{ props.event.name }} · </span>
                Alle Zeiten in {{ props.event.timezone }}
            </p>
        </header>

        <p v-if="props.days.length === 0" class="text-sm text-text-muted">
            Für diese Veranstaltung ist noch kein Programm veröffentlicht.
        </p>

        <template v-else-if="selectedDay">
            <nav
                v-if="props.days.length > 1"
                class="flex flex-wrap gap-2"
                aria-label="Tag wählen"
            >
                <Button
                    v-for="day in props.days"
                    :key="day.dayId"
                    type="button"
                    size="sm"
                    :variant="
                        day.dayId === selectedDay.dayId ? 'default' : 'outline'
                    "
                    :aria-pressed="day.dayId === selectedDay.dayId"
                    @click="selectedDayId = day.dayId"
                >
                    {{ day.label }}
                </Button>
            </nav>

            <div
                v-if="selectedDay.trackNames.length > 0"
                class="flex flex-wrap items-center gap-2"
            >
                <span class="text-sm text-text-muted">Tracks:</span>
                <Button
                    v-for="name in selectedDay.trackNames"
                    :key="name"
                    type="button"
                    size="sm"
                    :variant="
                        hiddenTracks.includes(name) ? 'outline' : 'secondary'
                    "
                    :aria-pressed="!hiddenTracks.includes(name)"
                    @click="toggleTrack(name)"
                >
                    {{ name }}
                </Button>
            </div>

            <!-- The same data twice, one shown at a time: the grid from the
                 `md` breakpoint up, the chronological list below it. The
                 table is wrapped rather than given the utility itself —
                 `display: block` on a `<table>` would undo its layout. -->
            <div class="hidden md:block">
                <ProgrammeGrid :day="selectedDay" :columns="columns" />
            </div>
            <div class="md:hidden">
                <ProgrammeList :day="selectedDay" :columns="columns" />
            </div>
        </template>
    </div>
</template>
