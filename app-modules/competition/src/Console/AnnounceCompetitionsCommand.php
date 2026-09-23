<?php

declare(strict_types=1);

namespace Konventix\Competition\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionAnnounced;
use Konventix\Competition\Models\Competition;

/**
 * Re-emits `CompetitionAnnounced` for every competition of every brand, in
 * every status, so a read model created after competitions were published can
 * be backfilled. Agenda's programme read model is the first such read model
 * (timetable design §4.5, #331).
 *
 * It uses the same pattern as `identity:announce-social-identities` and
 * `registration:announce-standing`: only a projection listens, so the command
 * can run any number of times, and once every row has been projected a run
 * changes nothing. It never re-emits `CompetitionPublished` (see the event's
 * docblock).
 *
 * `withoutBrandScope()` is load-bearing: the CLI resolves no brand, and the
 * brand scope would otherwise read nothing and report success. There is no
 * module gate either, for the same reason as the identity precedent: only
 * projections listen, and each decides for itself whether it is gated.
 */
final class AnnounceCompetitionsCommand extends Command
{
    protected $signature = 'competition:announce-competitions {--chunk=500 : Competitions per database round trip}';

    protected $description = 'Re-emit CompetitionAnnounced for every competition so downstream read models can be backfilled';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        Competition::withoutBrandScope()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $competitions) use (&$announced): void {
                /** @var Competition $competition */
                foreach ($competitions as $competition) {
                    event(new CompetitionAnnounced(
                        competitionId: $competition->id,
                        eventId: $competition->event_id,
                        brandId: $competition->brand_id,
                        name: $competition->name,
                        slug: $competition->slug,
                        status: $competition->status->value,
                        startsAt: $competition->starts_at?->toIso8601String(),
                        announced: $competition->status !== CompetitionStatus::Draft
                            && $competition->published_at !== null,
                    ));

                    $announced++;
                }
            });

        $this->components->info("Announced {$announced} competition(s).");

        return self::SUCCESS;
    }
}
