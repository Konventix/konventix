<?php

declare(strict_types=1);

namespace Konventix\Competition\Hub;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Competition\Data\BracketMatchData;
use Konventix\Competition\Data\BracketSideData;
use Konventix\Competition\Data\NextGameData;
use Konventix\Competition\Data\NextGamesCardData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\NextGameNudge;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Pages\MatchPowers;
use Konventix\Competition\Pages\TournamentPages;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `competition.next-games` card (design §10a,
 * D-HUB-07): the viewer's open matches at the running event, soonest first,
 * each with the one nudge that matters most right now.
 *
 * An invokable builder resolved per request by the closure in
 * `CompetitionServiceProvider::registerHub()` — `NowNextCard`'s shape — so
 * the singleton registry holds nothing of a request
 * (`.ai/rules/providers.md`).
 *
 * No `withoutBrandScope()`: the hub renders on the brand host inside a
 * request, so `BrandScope` is exactly the right filter (ADR-0010) and
 * another brand's matches are simply not there. The event filter is the
 * active event's ULID; a match at another event of the same brand never
 * appears.
 *
 * It nudges and nothing more: no write, no notification, no refusal. The
 * rows' opponents and powers come from the same reads as the match page
 * (`TournamentPages::bracketMatches()`, `MatchPowers`), so the card never
 * says "Ergebnis melden" where the page offers no form.
 */
final class NextGamesCard
{
    /** §10a: the card shows at most five matches and links to the rest. */
    public const int ROWS = 5;

    /** §10a "Liveness": the card refreshes itself from `NextGamesController` once a minute while a competition of the viewer's runs. */
    public const int POLL_SECONDS = 60;

    public function __construct(private readonly TournamentPages $pages) {}

    public function __invoke(ActiveEventData $event, Authenticatable $user): NextGamesCardData
    {
        $userId = (string) $user->getAuthIdentifier();

        /** @var Collection<string, Competition> $competitions */
        $competitions = Competition::query()
            ->where('event_id', $event->eventId)
            ->whereNotIn('status', [
                CompetitionStatus::Draft->value,
                CompetitionStatus::Finished->value,
                CompetitionStatus::Cancelled->value,
            ])
            ->get()
            ->keyBy('id');

        // The viewer's entrants that still play, as entrant => competition.
        // A withdrawn or disqualified entry has no matches left to nudge
        // about; an incomplete one is not in a bracket.
        $entrants = Entrant::query()
            ->whereIn('competition_id', $competitions->keys())
            ->whereIn('state', [EntrantState::Registered->value, EntrantState::CheckedIn->value])
            ->whereIn('competition_team_id', CompetitionTeamMember::query()
                ->select('competition_team_id')
                ->where('user_id', $userId))
            ->pluck('competition_id', 'id');

        /** @var Collection<int, CompetitionMatch> $matches */
        $matches = CompetitionMatch::query()
            ->whereIn('id', MatchSlot::query()
                ->select('competition_match_id')
                ->whereIn('entrant_id', $entrants->keys()))
            ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])
            // §10a: scheduled before unscheduled, soonest first, then the
            // bracket's own order; the ULID only makes a tie deterministic.
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('round')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::ROWS + 1)
            ->get();

        $hasMore = $matches->count() > self::ROWS;
        $matches = $matches->take(self::ROWS);

        /** @var array<string, BracketMatchData> $drawn */
        $drawn = [];

        foreach ($matches->groupBy('competition_id') as $competitionId => $ofCompetition) {
            /** @var Competition $owner */
            $owner = $competitions->get($competitionId);

            foreach ($this->pages->bracketMatches($owner, $ofCompetition) as $data) {
                $drawn[$data->id] = $data;
            }
        }

        $reports = MatchResultReport::query()
            ->whereIn('competition_match_id', $matches->modelKeys())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('competition_match_id');

        /** @var EventSummary|null $summary */
        $summary = EventSummary::query()->where('event_id', $event->eventId)->first();
        $slug = $this->eventSlug($summary, $event);
        $now = now();

        $games = $matches->map(function (CompetitionMatch $match) use ($competitions, $entrants, $drawn, $reports, $slug, $now): NextGameData {
            /** @var Competition $competition */
            $competition = $competitions->get($match->competition_id);
            $data = $drawn[$match->id];

            // The viewer's side: the slot one of their entrants holds.
            $sideId = null;

            foreach ($data->sides as $side) {
                if ($side->entrantId !== null && $entrants->has($side->entrantId)) {
                    $sideId = $side->entrantId;

                    break;
                }
            }

            // Only claims by a side standing in the match now, as on the
            // match page (`MatchPowers::currentClaims()`).
            /** @var Collection<int, MatchResultReport> $ofMatch */
            $ofMatch = MatchPowers::currentClaims($reports->get($match->id) ?? new Collection, $data);
            $own = $ofMatch->first(fn (MatchResultReport $report): bool => $report->entrant_id === $sideId);
            $opposing = $ofMatch->first(fn (MatchResultReport $report): bool => $report->entrant_id !== $sideId);

            $others = array_values(array_filter($data->sides, fn (BracketSideData $side): bool => $side->entrantId !== $sideId));
            $names = array_values(array_filter(array_map(fn (BracketSideData $side): ?string => $side->teamName, $others)));

            return new NextGameData(
                matchId: $match->id,
                competitionName: $competition->name,
                bracket: $match->bracket,
                round: $match->round,
                opponentName: $names === [] ? null : implode(', ', $names),
                awaitingOpponent: collect($others)->contains(fn (BracketSideData $side): bool => $side->awaiting),
                scheduledAt: $match->scheduled_at?->toIso8601String(),
                nudge: self::nudgeFor($match, MatchPowers::of($competition, $match, $data, $own, $opposing), $own, $opposing, $now),
                matchUrl: $slug === null
                    ? ''
                    : route('competition.match.page', [
                        'slug' => $slug,
                        'competition' => $competition->slug,
                        'match' => $match->id,
                    ], absolute: false),
            );
        });

        return new NextGamesCardData(
            games: array_values($games->all()),
            hasMore: $hasMore,
            myTournamentsUrl: route('competition.my.tournaments', absolute: false),
            nextGamesUrl: route('competition.hub.next-games', absolute: false),
            eventTimezone: $summary?->timezone,
            pollSeconds: $competitions
                ->only($entrants->unique()->values()->all())
                ->contains(fn (Competition $competition): bool => $competition->status === CompetitionStatus::Running)
                ? self::POLL_SECONDS
                : null,
        );
    }

    /**
     * §10a's nudge: the first of these that applies, in `NextGameNudge`'s
     * order. Public and static so the precedence can be read — and pinned —
     * apart from the queries above.
     *
     * Report before Due (fix round 1, M1): once a match is due or has no
     * time at all, and the viewer's side may report, reporting is the side's
     * next act under §8.2 — the page carries the form — so an overdue match
     * nobody has reported keeps asking for the report instead of saying
     * "Jetzt dran" forever. "Jetzt dran" is left for a due match the side
     * cannot report: a `desk_only` competition, or one whose proof the desk
     * collects. A match scheduled later carries no nudge.
     */
    public static function nudgeFor(
        CompetitionMatch $match,
        MatchPowers $powers,
        ?MatchResultReport $own,
        ?MatchResultReport $opposing,
        CarbonInterface $now,
    ): ?NextGameNudge {
        $due = $match->scheduled_at !== null && $match->scheduled_at->lessThanOrEqualTo($now);

        return match (true) {
            $match->isDisputed() => NextGameNudge::Disputed,
            $powers->canConfirm => NextGameNudge::Confirm,
            $own !== null && $opposing === null => NextGameNudge::AwaitingConfirmation,
            $powers->canReport && ($due || $match->scheduled_at === null) => NextGameNudge::Report,
            $due && $powers->everySide => NextGameNudge::Due,
            default => null,
        };
    }

    /**
     * Competition's own copy of the slug first (§10's projection), the
     * slug the hub carries second — the same event either way. Null when
     * neither knows one: then there is no public page to link, and an empty
     * link is what the component hides (`NowNextCard`'s rule).
     */
    private function eventSlug(?EventSummary $summary, ActiveEventData $event): ?string
    {
        $slug = $summary->slug ?? $event->eventSlug;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
