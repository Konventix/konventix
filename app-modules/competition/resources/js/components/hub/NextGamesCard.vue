<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/datetime';
import { sectionLabel } from '../bracket/bracketModel';
import { usePollWhile } from '../public/usePollWhile';

/**
 * The live hub's "Nächste Spiele" card (competition design §10a, D-HUB-07):
 * the viewer's open matches at the running event, soonest first, each with
 * the one nudge that matters most — "your round is due, your opponent is
 * waiting". It nudges and never blocks.
 *
 * The props are `NextGamesCardData`'s fields, spread by the hub shell
 * (`v-bind="card.payload"`). The URLs arrive in the payload because a hub
 * component imports no route helper (`tests/Feature/HubComponentBoundaryTest.php`);
 * the only things it takes from beside it are this module's own round
 * label and poll composable, which the match page uses too.
 *
 * `scheduledAt` is an instant (§10a "Time"), so it goes through
 * `formatDateTime()` in the reader's zone, as on the match page.
 *
 * While one of the viewer's competitions runs, the server sends
 * `pollSeconds` and the card reloads the hub's `hub` prop on that interval
 * (hub design §7.1). Platform has no per-card prop, so the reload rebuilds
 * every card — once a minute, and only while matches are being played.
 */
// Spelled out rather than `Konventix.Competition.Data.NextGamesCardData`: a
// bare namespaced type fails the SFC compiler's macro type resolver (see
// `agenda/hub/NowNextCard.vue`). Mirrors the Data class field for field.
type Props = {
    games: Konventix.Competition.Data.NextGameData[];
    hasMore: boolean;
    myTournamentsUrl: string;
    pollSeconds: number | null;
};

const props = defineProps<Props>();

usePollWhile(() => props.pollSeconds, ['hub']);

const NUDGES: Record<Konventix.Competition.Enums.NextGameNudge, string> = {
    disputed: 'Streitfall offen',
    confirm: 'Ergebnis bestätigen',
    awaiting_confirmation: 'Wartet auf Bestätigung',
    due: 'Jetzt dran',
    report: 'Ergebnis melden',
};

function roundLabel(game: Konventix.Competition.Data.NextGameData): string {
    return [sectionLabel(game.bracket), `Runde ${game.round}`]
        .filter((part) => part !== null)
        .join(' · ');
}

function opponentLabel(game: Konventix.Competition.Data.NextGameData): string {
    if (game.opponentName !== null) {
        return `gegen ${game.opponentName}`;
    }

    return game.awaitingOpponent ? 'wartet auf Gegner' : 'Freilos';
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <ul v-if="props.games.length > 0" class="flex flex-col gap-3">
            <li v-for="game in props.games" :key="game.matchId">
                <component
                    :is="game.matchUrl ? Link : 'div'"
                    :href="game.matchUrl || undefined"
                    class="flex flex-col gap-1 rounded-lg border border-border p-3"
                    :class="
                        game.matchUrl
                            ? 'hover:bg-subtle focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                            : ''
                    "
                >
                    <span class="flex items-start justify-between gap-2">
                        <span class="font-medium text-text">
                            {{ game.competitionName }}
                        </span>
                        <Badge
                            v-if="game.nudge"
                            :variant="
                                game.nudge === 'disputed'
                                    ? 'destructive'
                                    : game.nudge === 'awaiting_confirmation'
                                      ? 'outline'
                                      : 'default'
                            "
                        >
                            {{ NUDGES[game.nudge] }}
                        </Badge>
                    </span>
                    <span class="text-sm text-text-muted">
                        {{ roundLabel(game) }} · {{ opponentLabel(game) }}
                    </span>
                    <span class="text-sm text-text-muted">
                        {{
                            game.scheduledAt
                                ? formatDateTime(game.scheduledAt)
                                : 'Noch nicht angesetzt'
                        }}
                    </span>
                </component>
            </li>
        </ul>
        <p v-else class="text-sm text-text-muted">
            Gerade steht kein Spiel für dich an.
        </p>

        <Link
            :href="props.myTournamentsUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            {{
                props.hasMore
                    ? 'Alle Spiele in Meine Turniere'
                    : 'Meine Turniere'
            }}
        </Link>
    </div>
</template>
