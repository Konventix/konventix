<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { statusLabel } from '../public/labels';

/**
 * The live hub's "Meine Competitions" card (competition design §10a): the
 * competitions the viewer's teams are entered in at the running event, with
 * the status and — where the competition asks for one — the check-in.
 *
 * The props are `MyCompetitionsCardData`'s fields, spread by the hub shell
 * (`v-bind="card.payload"`); the URLs arrive in the payload because a hub
 * component imports no route helper
 * (`tests/Feature/HubComponentBoundaryTest.php`). The status labels are the
 * public pages' own (`../public/labels`).
 *
 * It does not poll, and nothing refreshes it: *Nächste Spiele* fetches only
 * its own payload from `/live/next-games` (design §10a, "Liveness"), so the
 * status and check-in shown here are the ones of the page load until the
 * hub is loaded again (final review, part 3, M3).
 */
// Spelled out rather than `Konventix.Competition.Data.MyCompetitionsCardData`
// (see `agenda/hub/NowNextCard.vue`). Mirrors the Data class field for field.
type Props = {
    entries: Konventix.Competition.Data.MyCompetitionEntryData[];
    hasMore: boolean;
    myTournamentsUrl: string;
    tournamentsUrl: string;
};

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <ul v-if="props.entries.length > 0" class="flex flex-col gap-2">
            <li
                v-for="entry in props.entries"
                :key="entry.competitionUrl + entry.competitionName"
                class="flex flex-col gap-0.5"
            >
                <Link
                    v-if="entry.competitionUrl"
                    :href="entry.competitionUrl"
                    class="font-medium text-text underline-offset-4 hover:underline"
                >
                    {{ entry.competitionName }}
                </Link>
                <span v-else class="font-medium text-text">
                    {{ entry.competitionName }}
                </span>
                <span class="text-sm text-text-muted">
                    {{ statusLabel(entry.status) }}
                    <template v-if="entry.checkInRequired">
                        ·
                        {{
                            entry.checkedIn
                                ? 'Eingecheckt'
                                : 'Noch nicht eingecheckt'
                        }}
                    </template>
                </span>
            </li>
        </ul>
        <p v-else class="text-sm text-text-muted">
            Du bist bei diesem Event noch für kein Turnier angemeldet.
            <Link
                v-if="props.tournamentsUrl"
                :href="props.tournamentsUrl"
                class="text-accent underline-offset-4 hover:underline"
            >
                Zu den Turnieren
            </Link>
        </p>

        <Link
            v-if="props.entries.length > 0"
            :href="props.myTournamentsUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            {{ props.hasMore ? 'Alle in Meine Turniere' : 'Meine Turniere' }}
        </Link>
    </div>
</template>
