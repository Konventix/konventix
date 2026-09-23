<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Event\Models\Event;
use Konventix\Registration\Models\Registration;

/**
 * Two eight-team brackets in the two states a tournament desk actually shows.
 *
 * The Main Cup is played to the final, because single-elimination standings
 * only exist once the final is decided — you finish where you were knocked
 * out, and a half-played bracket has knocked nobody out for good. The Side
 * Bracket is generated and its first round scheduled but unplayed: matches on
 * the clock, nothing recorded yet.
 *
 * Entry goes through `EnterCompetition`, which checks the eligibility read
 * model — so the entrants are people who actually hold a confirmed
 * registration for this event, and a demo where they did not would be
 * checking nothing. That is also why this slice runs after registrations.
 *
 * Two disciplines, because the kinds behave differently (ADR-0024): a quiz
 * needs no GameOps reference at all, a video game carries one.
 *
 * Idempotent per competition slug.
 */
final class DemoCompetitionSeeder
{
    /** A power of two: single elimination with no byes reads best in a demo. */
    private const int FIELD_SIZE = 8;

    public function __construct(
        private readonly EnterCompetition $enter,
        private readonly GenerateBracket $generateBracket,
        private readonly ScheduleMatch $scheduleMatch,
        private readonly RecordMatchResult $recordResult,
        private readonly AdvanceBracket $advanceBracket,
        private readonly CalculateStandings $calculateStandings,
        private readonly CloseSignup $closeSignup,
        private readonly StartCompetition $startCompetition,
    ) {}

    /**
     * @param  Collection<int, Registration>  $confirmed
     */
    public function handle(Event $event, Collection $confirmed): void
    {
        $slug = Str::slug($event->slug.'-main-cup');

        if (Competition::query()->where('slug', $slug)->exists()) {
            return;
        }

        $players = $confirmed
            ->pluck('registrant_user_id')
            ->filter()
            ->unique()
            ->values();

        if ($players->count() < self::FIELD_SIZE) {
            return;
        }

        // The quiz exists to show a non-video-game discipline; nothing is
        // played on it, which is a legitimate state for a signup-open side
        // event.
        Discipline::factory()->create([
            'kind' => DisciplineKind::Quiz,
            'name' => 'Pub quiz',
            'slug' => Str::slug($event->slug.'-pub-quiz'),
            'team_size' => 4,
            'avg_match_minutes' => 45,
        ]);

        $discipline = Discipline::factory()->videoGame()->create([
            'name' => 'Rocket Cars',
            'slug' => Str::slug($event->slug.'-rocket-cars'),
            'team_size' => 1,
            'avg_match_minutes' => 20,
        ]);

        $field = $players->take(self::FIELD_SIZE);

        $mainCup = $this->seedBracket($event, $discipline->getKey(), 'Main Cup', $slug, $field);

        if ($mainCup === null) {
            return;
        }

        // Played out, round by round, until the final decides it — the only
        // point at which single-elimination standings exist at all.
        $this->playThrough($mainCup, $event);

        $this->calculateStandings->handle($mainCup->refresh());

        // The other half of a tournament desk: a bracket drawn and timetabled,
        // with nothing recorded against it yet.
        $side = $this->seedBracket(
            $event,
            $discipline->getKey(),
            'Side Bracket',
            Str::slug($event->slug.'-side-bracket'),
            $field,
        );

        if ($side !== null) {
            $this->scheduleRound($side, $event, round: 1, at: 16);
        }
    }

    /**
     * A competition with its field entered and its bracket drawn, or null when
     * the field could not be filled.
     *
     * @param  Collection<int, string>  $players
     */
    private function seedBracket(
        Event $event,
        string $disciplineId,
        string $name,
        string $slug,
        Collection $players,
    ): ?Stage {
        $competition = Competition::factory()
            ->forEvent($event->getKey())
            ->create([
                'discipline_id' => $disciplineId,
                'name' => $name,
                'slug' => $slug,
            ]);

        if ($this->seedEntrants($competition, $players)->count() < self::FIELD_SIZE) {
            return null;
        }

        // The organizer's order of work (design §4): entering needs an open
        // signup (`EnterCompetition`), so the field is assembled while the
        // competition is `Published`; the bracket is drawn once signup is
        // closed (`GuardsCompetitionLifecycle`), and results are recorded
        // once it is `Running`, which in turn needs a drawn stage. Through
        // the lifecycle Actions rather than a status write, so the demo walks
        // the transitions — and their guards — the product walks.
        $competition = $this->closeSignup->handle($competition);

        $stage = Stage::factory()->format('single_elimination')->create([
            'competition_id' => $competition->getKey(),
            'name' => 'Playoffs',
        ]);

        $this->generateBracket->handle($stage);

        $this->startCompetition->handle($competition->refresh());

        return $stage;
    }

    /**
     * Every entrant is a team, solo players included (D-CMP-13): one shape
     * means the bracket engine never branches on whether a side is a person
     * or a squad.
     *
     * @param  Collection<int, string>  $userIds
     * @return Collection<int, Entrant>
     */
    private function seedEntrants(Competition $competition, Collection $userIds): Collection
    {
        return $userIds->values()->map(function (string $userId, int $index) use ($competition): Entrant {
            $name = 'Team '.($index + 1);

            // Teams are per event, not per competition (D-CMP-14), and their
            // names are unique within one — so a second bracket of the same
            // field enters the same teams rather than inventing new ones.
            $team = CompetitionTeam::query()
                ->where('event_id', $competition->event_id)
                ->firstWhere('name', $name)
                ?? CompetitionTeam::factory()
                    ->forEvent($competition->event_id)
                    ->create(['name' => $name]);

            $entrant = $this->enter->handle($competition, $team, [$userId]);

            // Seeding is the field's ordering, and the bracket is built from
            // it — without seeds round one would pair by creation order.
            $entrant->forceFill(['seed' => $index + 1])->save();

            return $entrant;
        });
    }

    /**
     * Every round in order: each match is scheduled, then decided, then the
     * winner is advanced along the graph. Advancing is a separate act because
     * the bracket edges — not the result — decide where a winner goes, and the
     * next round has no field until it has happened.
     */
    private function playThrough(Stage $stage, Event $event): void
    {
        for ($round = 1; $round <= self::FIELD_SIZE; $round++) {
            $matches = $this->pendingMatches($stage, $round);

            if ($matches->isEmpty()) {
                return;
            }

            foreach ($matches as $index => $match) {
                $slots = MatchSlot::query()
                    ->where('competition_match_id', $match->getKey())
                    ->orderBy('position')
                    ->get();

                if ($slots->count() < 2 || $slots->contains(fn (MatchSlot $slot): bool => $slot->entrant_id === null)) {
                    continue;
                }

                $this->scheduleMatch->handle(
                    $match,
                    $this->kickOff($event, 10 + $round, $index),
                );

                // The lower half wins the second match of every round; a
                // bracket where the seeding always holds makes a standings
                // table nobody needs to read.
                $winner = $index === 1 ? $slots[1] : $slots[0];
                $loser = $index === 1 ? $slots[0] : $slots[1];

                $this->recordResult->handle(
                    $match->refresh(),
                    [$winner->entrant_id => 3, $loser->entrant_id => 1],
                    $winner->entrant_id,
                );

                $this->advanceBracket->handle($match->refresh());
            }
        }
    }

    /**
     * One round given a kick-off time and nothing else.
     */
    private function scheduleRound(Stage $stage, Event $event, int $round, int $at): void
    {
        foreach ($this->pendingMatches($stage, $round) as $index => $match) {
            $this->scheduleMatch->handle($match, $this->kickOff($event, $at, $index));
        }
    }

    /**
     * @return Collection<int, CompetitionMatch>
     */
    private function pendingMatches(Stage $stage, int $round): Collection
    {
        return CompetitionMatch::query()
            ->where('stage_id', $stage->getKey())
            ->where('round', $round)
            ->where('state', MatchState::Pending)
            ->orderBy('position')
            ->get();
    }

    private function kickOff(Event $event, int $hour, int $index): CarbonInterface
    {
        return $event->starts_at->copy()->addDay()->setTime($hour, 0)->addMinutes($index * 30);
    }
}
