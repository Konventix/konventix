<?php

declare(strict_types=1);

namespace Konventix\Competition\Pages;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Konventix\Competition\Data\BracketConnectionData;
use Konventix\Competition\Data\BracketData;
use Konventix\Competition\Data\BracketMatchData;
use Konventix\Competition\Data\BracketSideData;
use Konventix\Competition\Data\CompetitionDetailData;
use Konventix\Competition\Data\CompetitionPageData;
use Konventix\Competition\Data\CompetitionViewerData;
use Konventix\Competition\Data\DeskStandingData;
use Konventix\Competition\Data\EnteredTeamData;
use Konventix\Competition\Data\EventRefData;
use Konventix\Competition\Data\EventTournamentsPageData;
use Konventix\Competition\Data\InterestedPersonData;
use Konventix\Competition\Data\MatchHistoryRowData;
use Konventix\Competition\Data\MatchPageData;
use Konventix\Competition\Data\MatchViewerData;
use Konventix\Competition\Data\TeamMatchData;
use Konventix\Competition\Data\TeamOptionData;
use Konventix\Competition\Data\TeamPageData;
use Konventix\Competition\Data\TournamentCardData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * The read side of the public and participant pages (design §10, D-CMP-22).
 *
 * **This runs on the brand's own host**, so `BrandScope` is left on and is
 * exactly the right filter: a competition, team or match of another brand is
 * not there, and every lookup that misses is a 404 — never a 403, which on a
 * ULID would answer whether it names something. A draft competition is not
 * there either (§4: "Not visible"), and neither is a competition of another
 * event under this event's slug.
 *
 * The bracket is built once per page as `BracketData` and handed to both of
 * its renderings — the canvas and the table read the same matches, so they
 * cannot disagree about who plays whom (D-CMP-22). Names are read in one
 * query each for the whole field, as the desk does, rather than per match.
 *
 * Person names come from `ParticipantProfile` (§10), never from Identity.
 */
final class TournamentPages
{
    /** §10 "Freshness": Inertia polling every 30 s while `Running`. */
    public const int POLL_SECONDS = 30;

    public function event(string $slug): EventSummary
    {
        /** @var EventSummary|null $event */
        $event = EventSummary::query()->where('slug', $slug)->first();

        // A draft (or announced, cancelled, archived) event is not public,
        // whatever its competitions say — the Event module's own rule for
        // its landing page, and Agenda's for its programme.
        abort_if($event === null || $event->name === null || ! $event->isPublic(), 404);

        return $event;
    }

    /**
     * A competition of this event that the public may see (§4).
     */
    public function competition(EventSummary $event, string $slug): Competition
    {
        /** @var Competition|null $competition */
        $competition = Competition::query()
            ->where('event_id', $event->event_id)
            ->where('slug', $slug)
            ->first();

        abort_if($competition === null || ! $competition->status->isPubliclyVisible(), 404);

        return $competition;
    }

    /**
     * Every competition of the event that is not a draft, soonest first.
     */
    public function index(EventSummary $event): EventTournamentsPageData
    {
        /** @var Collection<int, Competition> $competitions */
        $competitions = Competition::query()
            ->where('event_id', $event->event_id)
            ->where('status', '!=', CompetitionStatus::Draft->value)
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();

        $ids = $competitions->modelKeys();

        $disciplines = Discipline::query()
            ->whereIn('id', $competitions->pluck('discipline_id')->unique())
            ->get()
            ->keyBy('id');

        $formats = Stage::query()
            ->whereIn('competition_id', $ids)
            ->orderBy('sequence')
            ->get()
            ->groupBy('competition_id')
            ->map(fn (Collection $stages): string => (string) $stages->first()?->format);

        /** @var BaseCollection<string, int> $entrants */
        $entrants = Entrant::query()
            ->whereIn('competition_id', $ids)
            ->whereIn('state', Entrant::slotHoldingStates())
            ->selectRaw('competition_id, count(*) as aggregate')
            ->groupBy('competition_id')
            ->pluck('aggregate', 'competition_id');

        return new EventTournamentsPageData(
            event: $this->eventRef($event),
            competitions: $competitions->map(function (Competition $competition) use ($disciplines, $formats, $entrants): TournamentCardData {
                /** @var Discipline|null $discipline */
                $discipline = $disciplines->get($competition->discipline_id);

                return new TournamentCardData(
                    slug: $competition->slug,
                    name: $competition->name,
                    disciplineName: $discipline->name ?? '—',
                    format: $formats->get($competition->id),
                    teamSize: $discipline->team_size ?? 1,
                    status: $competition->status,
                    entrants: (int) ($entrants[$competition->id] ?? 0),
                    maxEntrants: $competition->max_entrants,
                    startsAt: $competition->starts_at?->toIso8601String(),
                );
            })->values()->all(),
            pollSeconds: $competitions->contains(
                fn (Competition $competition): bool => $competition->status === CompetitionStatus::Running,
            ) ? self::POLL_SECONDS : null,
        );
    }

    public function competitionPage(EventSummary $event, Competition $competition, ?string $userId): CompetitionPageData
    {
        $stage = $this->stageOf($competition);

        /** @var Collection<int, Entrant> $entrants */
        $entrants = Entrant::query()
            ->where('competition_id', $competition->id)
            ->orderByRaw('seed IS NULL')
            ->orderBy('seed')
            ->orderBy('created_at')
            ->get();

        $teams = $this->teamsOf($entrants);
        $rosters = $this->rostersOf($teams->modelKeys());
        $names = ParticipantProfile::displayNamesFor(array_values(array_unique(array_merge(...array_values($rosters) ?: [[]]))));

        $interests = CompetitionInterest::forCompetition($competition->brand_id, $competition->id);
        $interestNames = ParticipantProfile::displayNamesFor(array_values($interests->pluck('user_id')->all()));

        return new CompetitionPageData(
            event: $this->eventRef($event),
            competition: $this->detail($competition, $stage),
            teams: $entrants
                ->filter(fn (Entrant $entrant): bool => in_array($entrant->state->value, Entrant::slotHoldingStates(), true))
                ->map(fn (Entrant $entrant): EnteredTeamData => new EnteredTeamData(
                    teamId: $entrant->competition_team_id,
                    name: $teams->get($entrant->competition_team_id)->name ?? '—',
                    state: $entrant->state,
                    members: array_map(
                        fn (string $memberId): string => $names[$memberId] ?? '—',
                        $rosters[$entrant->competition_team_id] ?? [],
                    ),
                ))
                ->values()
                ->all(),
            interested: $interests->map(fn (CompetitionInterest $interest): InterestedPersonData => new InterestedPersonData(
                displayName: $interestNames[$interest->user_id] ?? '—',
                note: $interest->note,
            ))->values()->all(),
            bracket: $stage === null || $stage->isLeaderboard() || ! $stage->isGenerated() ? null : $this->bracket($competition, $stage),
            standings: $stage === null ? [] : $this->standings($stage, $entrants, $teams),
            viewer: $this->competitionViewer($competition, $stage, $entrants, $userId),
            pollSeconds: $this->pollFor($competition),
        );
    }

    public function matchPage(EventSummary $event, Competition $competition, string $matchId, ?string $userId): MatchPageData
    {
        /** @var CompetitionMatch|null $match */
        $match = CompetitionMatch::query()
            ->where('competition_id', $competition->id)
            ->whereKey($matchId)
            ->first();

        abort_if($match === null, 404);

        $bracketMatch = $this->bracketMatches($competition, new Collection([$match]))[0];
        $viewer = $this->matchViewer($competition, $match, $bracketMatch, $userId);

        return new MatchPageData(
            event: $this->eventRef($event),
            competition: $this->detail($competition, $this->stageOf($competition)),
            match: $bracketMatch,
            // §8.6: the history is shown "to the sides and the desk".
            history: $viewer->position === null ? [] : $this->history($match),
            viewer: $viewer,
            pollSeconds: $this->pollFor($competition),
        );
    }

    /**
     * A team on the page of a competition it is entered in, with its matches
     * across the whole event (§10) — in every competition of the event the
     * public may see.
     */
    public function teamPage(EventSummary $event, Competition $competition, string $teamId): TeamPageData
    {
        $entered = Entrant::query()
            ->where('competition_id', $competition->id)
            ->where('competition_team_id', $teamId)
            ->exists();

        /** @var CompetitionTeam|null $team */
        $team = $entered ? CompetitionTeam::query()->whereKey($teamId)->first() : null;

        abort_if($team === null, 404);

        $roster = $team->memberUserIds();
        $names = ParticipantProfile::displayNamesFor($roster);

        /** @var Collection<int, Competition> $competitions */
        $competitions = Competition::query()
            ->where('event_id', $team->event_id)
            ->where('status', '!=', CompetitionStatus::Draft->value)
            ->get()
            ->keyBy('id');

        $entrantIds = Entrant::query()
            ->where('competition_team_id', $team->id)
            ->whereIn('competition_id', $competitions->modelKeys())
            ->pluck('id');

        /** @var Collection<int, CompetitionMatch> $matches */
        $matches = CompetitionMatch::query()
            ->whereIn('id', MatchSlot::query()->select('competition_match_id')->whereIn('entrant_id', $entrantIds))
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        $matchData = [];

        foreach ($matches->groupBy('competition_id') as $competitionId => $ofCompetition) {
            /** @var Competition $owner */
            $owner = $competitions->get($competitionId);

            foreach ($this->bracketMatches($owner, $ofCompetition) as $data) {
                $matchData[] = new TeamMatchData(
                    competitionSlug: $owner->slug,
                    competitionName: $owner->name,
                    match: $data,
                );
            }
        }

        return new TeamPageData(
            event: $this->eventRef($event),
            competition: $this->detail($competition, $this->stageOf($competition)),
            teamName: $team->name,
            members: array_map(fn (string $memberId): string => $names[$memberId] ?? '—', $roster),
            matches: $matchData,
            pollSeconds: $competitions->contains(
                fn (Competition $candidate): bool => $candidate->status === CompetitionStatus::Running,
            ) ? self::POLL_SECONDS : null,
        );
    }

    /**
     * The drawn stage as both renderings read it (D-CMP-22): the matches in
     * play order and the progression graph between them.
     */
    public function bracket(Competition $competition, Stage $stage): BracketData
    {
        /** @var Collection<int, CompetitionMatch> $matches */
        $matches = CompetitionMatch::query()
            ->where('stage_id', $stage->id)
            // Double elimination's three brackets in the order they are
            // played; a group stage's `group-a`, `group-b`… and the
            // one-bracket formats' null fall through to the name — the desk's
            // order (`TournamentDesk`).
            ->orderByRaw("CASE bracket WHEN 'winners' THEN 1 WHEN 'losers' THEN 2 WHEN 'grand_final' THEN 3 ELSE 0 END")
            ->orderBy('bracket')
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        return new BracketData(
            format: $stage->format,
            matches: $this->bracketMatches($competition, $matches),
            connections: MatchConnection::query()
                ->whereIn('source_match_id', $matches->modelKeys())
                ->orderBy('id')
                ->get()
                ->map(fn (MatchConnection $edge): BracketConnectionData => new BracketConnectionData(
                    sourceMatchId: $edge->source_match_id,
                    outcome: $edge->source_outcome,
                    targetMatchId: $edge->target_match_id,
                    targetSlot: $edge->target_slot,
                ))
                ->values()
                ->all(),
        );
    }

    public function detail(Competition $competition, ?Stage $stage): CompetitionDetailData
    {
        /** @var Discipline|null $discipline */
        $discipline = Discipline::query()->whereKey($competition->discipline_id)->first();

        $settings = $stage !== null && $stage->isLeaderboard() ? $stage->leaderboardSettings() : null;

        return new CompetitionDetailData(
            id: $competition->id,
            slug: $competition->slug,
            name: $competition->name,
            status: $competition->status,
            descriptionHtml: $this->markdown($competition->description),
            rulesHtml: $this->markdown($competition->rules),
            disciplineName: $discipline->name ?? '—',
            teamSize: $discipline->team_size ?? 1,
            format: $stage?->format,
            entrants: Entrant::countSlotHolders($competition->brand_id, $competition->id),
            maxEntrants: $competition->max_entrants,
            startsAt: $competition->starts_at?->toIso8601String(),
            signupOpen: $competition->signupIsOpen(),
            resultSubmissionMode: $competition->result_submission_mode,
            proofRequired: $competition->proof_policy === ProofPolicy::Required,
            unit: $settings?->unit,
            heats: $settings?->heats,
        );
    }

    /**
     * The first stage, and in this slice the only one (§7.3).
     */
    public function stageOf(Competition $competition): ?Stage
    {
        /** @var Stage|null */
        return Stage::query()
            ->where('competition_id', $competition->id)
            ->orderBy('sequence')
            ->first();
    }

    /**
     * The viewer's own entrant in the competition: one whose team they are
     * on and which still plays (`registered` or `checked_in`).
     */
    public function ownEntrant(Competition $competition, string $userId): ?Entrant
    {
        /** @var Entrant|null */
        return Entrant::query()
            ->where('competition_id', $competition->id)
            ->whereIn('state', [EntrantState::Registered->value, EntrantState::CheckedIn->value])
            ->whereIn('competition_team_id', CompetitionTeamMember::query()
                ->select('competition_team_id')
                ->where('user_id', $userId))
            ->first();
    }

    /**
     * The viewer's side of a match: the entrant in one of its slots whose
     * team they are on. Null for anybody else, which is what keeps report,
     * confirm and dispute to "a member of a side" (§10, §11).
     */
    public function ownSide(CompetitionMatch $match, string $userId): ?Entrant
    {
        /** @var Entrant|null */
        return Entrant::query()
            ->whereIn('id', MatchSlot::query()
                ->select('entrant_id')
                ->where('competition_match_id', $match->id)
                ->whereNotNull('entrant_id'))
            ->whereIn('competition_team_id', CompetitionTeamMember::query()
                ->select('competition_team_id')
                ->where('user_id', $userId))
            ->first();
    }

    /**
     * Matches of one competition as the pages draw them: sides with team
     * names, scores, and whether an empty slot still waits on an undecided
     * match. Public for the hub's *Nächste Spiele* (§10a), which must name
     * the same opponent the match page does.
     *
     * @param  Collection<int, CompetitionMatch>  $matches
     * @return list<BracketMatchData>
     */
    public function bracketMatches(Competition $competition, Collection $matches): array
    {
        $slots = MatchSlot::query()
            ->whereIn('competition_match_id', $matches->modelKeys())
            ->orderBy('position')
            ->get()
            ->groupBy('competition_match_id');

        $entrants = Entrant::query()
            ->where('competition_id', $competition->id)
            ->whereIn('id', $slots->flatten()->pluck('entrant_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $teams = $this->teamsOf($entrants);

        // Empty slots still fed by an undecided match, as `match|slot` —
        // `SettlesAMatch::guardEverySideHasArrived()`'s rule, which the desk
        // reads the same way.
        $awaited = MatchConnection::query()
            ->whereIn('target_match_id', $matches->modelKeys())
            ->whereIn('source_match_id', CompetitionMatch::query()
                ->select('id')
                ->where('competition_id', $competition->id)
                ->where('state', '!=', MatchState::Finished->value))
            ->get(['target_match_id', 'target_slot'])
            ->map(fn (MatchConnection $edge): string => $edge->target_match_id.'|'.$edge->target_slot)
            ->flip();

        return array_values($matches->map(fn (CompetitionMatch $match): BracketMatchData => new BracketMatchData(
            id: $match->id,
            bracket: $match->bracket,
            round: $match->round,
            position: $match->position,
            state: $match->state,
            scheduledAt: $match->scheduled_at?->toIso8601String(),
            disputed: $match->isDisputed(),
            sides: array_values(($slots->get($match->id) ?? new Collection)->map(
                function (MatchSlot $slot) use ($match, $entrants, $teams, $awaited): BracketSideData {
                    /** @var Entrant|null $entrant */
                    $entrant = $slot->entrant_id === null ? null : $entrants->get($slot->entrant_id);
                    $teamId = $entrant?->competition_team_id;

                    return new BracketSideData(
                        position: $slot->position,
                        entrantId: $slot->entrant_id,
                        teamId: $teamId,
                        teamName: $teamId === null ? null : ($teams->get($teamId)->name ?? '—'),
                        score: $slot->score,
                        winner: $slot->entrant_id !== null && $slot->entrant_id === $match->winner_entrant_id,
                        awaiting: $slot->entrant_id === null && $awaited->has($match->id.'|'.$slot->position),
                    );
                },
            )->all()),
        ))->all());
    }

    /**
     * @param  Collection<int, Entrant>  $entrants
     * @param  Collection<string, CompetitionTeam>  $teams
     * @return list<DeskStandingData>
     */
    private function standings(Stage $stage, Collection $entrants, Collection $teams): array
    {
        $teamOf = $entrants->keyBy('id');

        return array_values(Standing::query()
            ->where('stage_id', $stage->id)
            ->orderBy('group')
            ->orderByRaw('placement IS NULL')
            ->orderBy('placement')
            ->get()
            ->map(function (Standing $row) use ($teamOf, $teams): DeskStandingData {
                /** @var Entrant|null $entrant */
                $entrant = $teamOf->get($row->entrant_id);

                return new DeskStandingData(
                    entrantId: $row->entrant_id,
                    teamName: $entrant === null ? '—' : ($teams->get($entrant->competition_team_id)->name ?? '—'),
                    group: $row->group,
                    placement: $row->placement,
                    wins: $row->wins,
                    losses: $row->losses,
                    draws: $row->draws,
                    points: $row->points,
                    tiebreaker: $row->tiebreaker,
                );
            })
            ->all());
    }

    /**
     * @param  Collection<int, Entrant>  $entrants
     */
    private function competitionViewer(Competition $competition, ?Stage $stage, Collection $entrants, ?string $userId): CompetitionViewerData
    {
        if ($userId === null) {
            return new CompetitionViewerData(
                signedIn: false,
                eligible: false,
                enteredTeamName: null,
                enterableTeams: [],
                interested: false,
                canSubmitScore: false,
            );
        }

        $eligible = EligibleRegistration::userMayEnter($competition->brand_id, $competition->event_id, $userId);

        $myTeamIds = CompetitionTeamMember::query()->where('user_id', $userId)->pluck('competition_team_id');

        /** @var Entrant|null $entered */
        $entered = $entrants->first(fn (Entrant $entrant): bool => $myTeamIds->contains($entrant->competition_team_id)
            && in_array($entrant->state->value, Entrant::slotHoldingStates(), true));

        $enteredTeamName = null;

        if ($entered !== null) {
            /** @var CompetitionTeam|null $enteredTeam */
            $enteredTeam = CompetitionTeam::query()->whereKey($entered->competition_team_id)->first();
            $enteredTeamName = $enteredTeam->name ?? '—';
        }

        return new CompetitionViewerData(
            signedIn: true,
            eligible: $eligible,
            enteredTeamName: $enteredTeamName,
            enterableTeams: $eligible && $entered === null && $competition->signupIsOpen()
                ? $this->enterableTeams($competition, $userId, $entrants)
                : [],
            interested: CompetitionInterest::query()
                ->where('competition_id', $competition->id)
                ->where('user_id', $userId)
                ->exists(),
            canSubmitScore: $stage !== null
                && $stage->isLeaderboard()
                && $competition->status === CompetitionStatus::Running
                && $competition->result_submission_mode === ResultSubmissionMode::Participants
                && $competition->proof_policy !== ProofPolicy::Required
                && $entered !== null
                && $entered->isActive(),
        );
    }

    /**
     * Teams at this event the viewer captains and that hold no live entry in
     * this competition yet.
     *
     * @param  Collection<int, Entrant>  $entrants
     * @return list<TeamOptionData>
     */
    private function enterableTeams(Competition $competition, string $userId, Collection $entrants): array
    {
        $entered = $entrants
            ->filter(fn (Entrant $entrant): bool => in_array($entrant->state->value, Entrant::slotHoldingStates(), true))
            ->pluck('competition_team_id')
            ->all();

        $captained = CompetitionTeamMember::query()
            ->where('user_id', $userId)
            ->where('role', TeamRole::Captain->value)
            ->pluck('competition_team_id');

        /** @var BaseCollection<string, int> $sizes */
        $sizes = CompetitionTeamMember::query()
            ->whereIn('competition_team_id', $captained)
            ->selectRaw('competition_team_id, count(*) as aggregate')
            ->groupBy('competition_team_id')
            ->pluck('aggregate', 'competition_team_id');

        return array_values(CompetitionTeam::query()
            ->whereIn('id', $captained)
            ->where('event_id', $competition->event_id)
            ->whereNotIn('id', $entered)
            ->orderBy('name')
            ->get()
            ->map(fn (CompetitionTeam $team): TeamOptionData => new TeamOptionData(
                id: $team->id,
                name: $team->name,
                members: (int) ($sizes[$team->id] ?? 0),
            ))
            ->all());
    }

    private function matchViewer(Competition $competition, CompetitionMatch $match, BracketMatchData $data, ?string $userId): MatchViewerData
    {
        $side = $userId === null ? null : $this->ownSide($match, $userId);

        if ($side === null) {
            return new MatchViewerData(
                position: null,
                ownReport: null,
                opposingReport: null,
                canReport: false,
                canConfirm: false,
                canDispute: false,
            );
        }

        $position = null;

        foreach ($data->sides as $slot) {
            if ($slot->entrantId === $side->id) {
                $position = $slot->position;
            }
        }

        /** @var Collection<int, MatchResultReport> $reports */
        $reports = MatchResultReport::query()
            ->where('competition_match_id', $match->id)
            ->orderBy('created_at')
            ->get();

        $own = $reports->first(fn (MatchResultReport $report): bool => $report->entrant_id === $side->id);
        $opposing = $reports->first(fn (MatchResultReport $report): bool => $report->entrant_id !== $side->id);

        $powers = MatchPowers::of($competition, $match, $data, $own, $opposing);

        return new MatchViewerData(
            position: $position,
            ownReport: $own?->normalisedScores(),
            opposingReport: $opposing?->normalisedScores(),
            canReport: $powers->canReport,
            canConfirm: $powers->canConfirm,
            canDispute: $powers->canDispute,
        );
    }

    /**
     * @return list<MatchHistoryRowData>
     */
    private function history(CompetitionMatch $match): array
    {
        /** @var Collection<int, MatchHistoryEntry> $rows */
        $rows = $match->history()->get();

        $payloads = $rows->map(fn (MatchHistoryEntry $row) => $row->payloadData());

        $entrantIds = $payloads->pluck('entrantId')->filter()->unique();

        $teamIds = Entrant::query()->whereIn('id', $entrantIds)->pluck('competition_team_id', 'id');
        $teamNames = CompetitionTeam::query()->whereIn('id', $teamIds->unique())->pluck('name', 'id');

        return array_values($rows->map(function (MatchHistoryEntry $row, int $index) use ($payloads, $teamIds, $teamNames): MatchHistoryRowData {
            $payload = $payloads[$index];
            $teamId = $payload->entrantId === null ? null : ($teamIds[$payload->entrantId] ?? null);

            return new MatchHistoryRowData(
                kind: $row->kind,
                at: $row->created_at?->toIso8601String(),
                teamName: $teamId === null ? null : ($teamNames[$teamId] ?? null),
                scores: $payload->scores,
                note: $payload->note,
            );
        })->all());
    }

    /**
     * @param  Collection<int, Entrant>  $entrants
     * @return Collection<string, CompetitionTeam>
     */
    private function teamsOf(Collection $entrants): Collection
    {
        /** @var Collection<string, CompetitionTeam> */
        return CompetitionTeam::query()
            ->whereIn('id', $entrants->pluck('competition_team_id')->unique())
            ->get()
            ->keyBy('id');
    }

    /**
     * Each team's roster as user ULIDs, oldest member first.
     *
     * @param  list<string>|array<int, string>  $teamIds
     * @return array<string, list<string>>
     */
    private function rostersOf(array $teamIds): array
    {
        $rosters = [];

        foreach (CompetitionTeamMember::query()
            ->whereIn('competition_team_id', $teamIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $member) {
            $rosters[$member->competition_team_id][] = $member->user_id;
        }

        return $rosters;
    }

    /**
     * The organizer's markdown (§9: description and rules are markdown) as
     * HTML that is safe to bind on a public page: raw HTML in the source is
     * stripped rather than escaped, and `javascript:`/`data:` link targets
     * are dropped — the configuration Compliance's `PolicyMarkdown` uses for
     * the same reason.
     */
    private function markdown(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        return Str::markdown($source, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    private function eventRef(EventSummary $event): EventRefData
    {
        return new EventRefData(id: $event->event_id, slug: (string) $event->slug, name: (string) $event->name);
    }

    private function pollFor(Competition $competition): ?int
    {
        return $competition->status === CompetitionStatus::Running ? self::POLL_SECONDS : null;
    }
}
