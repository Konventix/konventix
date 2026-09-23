<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;

/**
 * @extends Factory<Entrant>
 */
class EntrantFactory extends Factory
{
    /** @var class-string<Entrant> */
    protected $model = Entrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            // A team at the competition's own event, as signup would only
            // ever make one (design §5.2). The competition is resolved first
            // — attributes expand in order — so its event is known here; a
            // test that passes its own team skips this entirely.
            'competition_team_id' => static function (array $attributes): string {
                $eventId = self::eventOf($attributes['competition_id'] ?? null);
                $team = $eventId === null ? CompetitionTeam::factory() : CompetitionTeam::factory()->forEvent($eventId);

                return (string) $team->create()->getKey();
            },
            'state' => EntrantState::Registered,
            'seed' => null,
            'checked_in_at' => null,
        ];
    }

    /**
     * The event of the competition the entrant is made for, or null where
     * there is none to ask (a factory told not to make its parents).
     */
    private static function eventOf(mixed $competitionId): ?string
    {
        if ($competitionId instanceof Competition) {
            return $competitionId->event_id;
        }

        if (! is_string($competitionId) || $competitionId === '') {
            return null;
        }

        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($competitionId)->first();

        return $competition?->event_id;
    }
}
