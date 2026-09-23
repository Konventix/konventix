<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { sectionLabel } from '../bracket/bracketModel';
import { formatEventDateTime } from '../public/eventTime';

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
 * label and event-time formatter, which the match page uses too.
 *
 * `scheduledAt` is an instant shown on the event's wall clock
 * (`eventTimezone`, ADR-0027, §10a "Time"), as on the match page.
 *
 * Liveness (hub design §7.1, §10a): while one of the viewer's competitions
 * runs, the server sends `pollSeconds`, and the card fetches its own payload
 * from `nextGamesUrl` on that interval into local state — the shoutbox
 * card's `messagesUrl` shape. The rest of the hub is never rebuilt for it,
 * and a refresh is not a hub view. A failed fetch keeps what is on screen
 * and tries again on the next tick; a hidden tab skips its ticks.
 */
// Spelled out rather than `Konventix.Competition.Data.NextGamesCardData`: a
// bare namespaced type fails the SFC compiler's macro type resolver (see
// `agenda/hub/NowNextCard.vue`). Mirrors the Data class field for field.
type Props = {
    games: Konventix.Competition.Data.NextGameData[];
    hasMore: boolean;
    myTournamentsUrl: string;
    nextGamesUrl: string;
    eventTimezone: string | null;
    pollSeconds: number | null;
};

const props = defineProps<Props>();

const card = ref<Konventix.Competition.Data.NextGamesCardData>({
    games: props.games,
    hasMore: props.hasMore,
    myTournamentsUrl: props.myTournamentsUrl,
    nextGamesUrl: props.nextGamesUrl,
    eventTimezone: props.eventTimezone,
    pollSeconds: props.pollSeconds,
});

let timer: ReturnType<typeof setTimeout> | null = null;

function schedule(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    // Null once nothing of the viewer's runs any more: stop asking.
    if (card.value.pollSeconds !== null) {
        timer = setTimeout(refresh, card.value.pollSeconds * 1000);
    }
}

async function refresh(): Promise<void> {
    if (!document.hidden) {
        try {
            // Same-origin GET of this module's own endpoint, whose URL the
            // server put in the payload — the shoutbox's `fetchFeed()` shape.
            // gitlab-advanced-sast-exclude
            const response = await fetch(card.value.nextGamesUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (response.ok) {
                card.value =
                    (await response.json()) as Konventix.Competition.Data.NextGamesCardData;
            }
        } catch {
            // Keep the last answer on screen; the next tick tries again.
        }
    }

    schedule();
}

// Started on mount only: there is nothing to poll during server rendering.
onMounted(schedule);
onUnmounted(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

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
        <ul v-if="card.games.length > 0" class="flex flex-col gap-3">
            <li v-for="game in card.games" :key="game.matchId">
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
                                ? formatEventDateTime(
                                      game.scheduledAt,
                                      card.eventTimezone,
                                  )
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
            :href="card.myTournamentsUrl"
            class="self-start text-sm text-accent underline-offset-4 hover:underline"
        >
            {{
                card.hasMore
                    ? 'Alle Spiele in Meine Turniere'
                    : 'Meine Turniere'
            }}
        </Link>
    </div>
</template>
