<?php

declare(strict_types=1);

namespace Konventix\Competition\Hub;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Competition\Data\MyCompetitionEntryData;
use Konventix\Competition\Data\MyCompetitionsCardData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `competition.my-competitions` card (design
 * §10a): the competitions the viewer's teams are entered in at the running
 * event, with their status and, where the competition asks for one, whether
 * the team has checked in.
 *
 * `NextGamesCard`'s shape and its scope: resolved per request by the
 * provider's closure, `BrandScope` left on (the hub renders on the brand
 * host), and only the active event's competitions — never a draft, which the
 * public may not see (§4).
 *
 * An entry is any slot-holding entrant (`Entrant::slotHoldingStates()`),
 * `incomplete` included: a team still short of players is entered, and the
 * card is where they would notice. A withdrawn or disqualified entry is not.
 */
final class MyCompetitionsCard
{
    /** §10a: the card shows at most five competitions and links to the rest. */
    public const int ROWS = 5;

    public function __invoke(ActiveEventData $event, Authenticatable $user): MyCompetitionsCardData
    {
        $userId = (string) $user->getAuthIdentifier();

        $entrants = Entrant::query()
            ->whereIn('competition_id', Competition::query()
                ->select('id')
                ->where('event_id', $event->eventId)
                ->where('status', '!=', CompetitionStatus::Draft->value))
            ->whereIn('competition_team_id', CompetitionTeamMember::query()
                ->select('competition_team_id')
                ->where('user_id', $userId))
            ->whereIn('state', Entrant::slotHoldingStates())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            // One row per competition; the oldest entry if the viewer is
            // somehow on two entered teams of the same one.
            ->unique('competition_id')
            ->keyBy('competition_id');

        /** @var Collection<int, Competition> $competitions */
        $competitions = Competition::query()
            ->whereIn('id', $entrants->keys())
            // The event's tournament index's order (`TournamentPages::index`).
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $slug = $this->eventSlug($event);

        return new MyCompetitionsCardData(
            entries: array_values($competitions
                ->take(self::ROWS)
                ->map(function (Competition $competition) use ($entrants, $slug): MyCompetitionEntryData {
                    /** @var Entrant $entrant */
                    $entrant = $entrants->get($competition->id);

                    return new MyCompetitionEntryData(
                        competitionName: $competition->name,
                        status: $competition->status,
                        checkInRequired: $competition->check_in_mode->requiresCheckIn(),
                        checkedIn: $entrant->state === EntrantState::CheckedIn,
                        competitionUrl: $slug === null
                            ? ''
                            : route('competition.page', ['slug' => $slug, 'competition' => $competition->slug], absolute: false),
                    );
                })
                ->all()),
            hasMore: $competitions->count() > self::ROWS,
            myTournamentsUrl: route('competition.my.tournaments', absolute: false),
            tournamentsUrl: $slug === null ? '' : route('competition.event.tournaments', ['slug' => $slug], absolute: false),
        );
    }

    /** `NextGamesCard::eventSlug()`'s rule. */
    private function eventSlug(ActiveEventData $event): ?string
    {
        $slug = EventSummary::query()->where('event_id', $event->eventId)->value('slug') ?? $event->eventSlug;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
