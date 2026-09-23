<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\RefusesOnceAResultIsRecorded;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Seeding\SeedSource;
use Konventix\Competition\Seeding\SeedSourceRegistry;

/**
 * Writes a stage's seed order into `entrants.seed` (design §7.3, §7.5).
 *
 * The generalisation of what `GenerateBracket` used to do inline: "resolve
 * the entrants into a seed order" is now its own step with its own source,
 * so a later stage can take its field from the previous stage's standings
 * without the bracket code learning anything new (§7.3).
 *
 * Two ways in, and they are the two the desk has:
 *
 * - **no source** — the registry answers for the stage's `entry_source`,
 *   which is `RandomSeedSource` for a signup stage. This is "shuffle".
 * - **an explicit source** — `ManualSeedSource` carrying the order the desk
 *   dragged. This is "apply this order".
 *
 * Seeds are renumbered 1..n over the whole competition's entrants, and
 * anybody the source did not return is cleared to null rather than left
 * holding a stale number: an entrant who withdrew between two shuffles must
 * not keep seed 3 and reappear ahead of the field the next time something
 * orders by it.
 *
 * Seeding is part of the draw, so it happens where the draw does — once
 * signup is closed, and while the competition runs until the first result
 * (§4, `GuardsCompetitionLifecycle`).
 *
 * Refuses once the stage has a recorded result. Seeds break ties in every
 * standings calculator, so renumbering after a result rewrites the table the
 * results produced.
 */
final class SeedStage
{
    use GuardsCompetitionLifecycle;
    use RefusesOnceAResultIsRecorded;

    public function __construct(
        private readonly SeedSourceRegistry $sources,
    ) {}

    /**
     * @return list<string> the seed order actually written; index 0 is seed 1
     */
    public function handle(Stage $stage, ?SeedSource $source = null): array
    {
        return DB::transaction(function () use ($stage, $source): array {
            $this->guardDrawIsOpen($stage->competition_id);
            $this->guardNoRecordedResult($stage);

            $order = ($source ?? $this->sources->get($stage->entry_source))->seedOrder($stage);

            Entrant::withoutBrandScope()
                ->where('brand_id', $stage->brand_id)
                ->where('competition_id', $stage->competition_id)
                ->whereNotNull('seed')
                ->update(['seed' => null]);

            foreach ($order as $index => $entrantId) {
                Entrant::withoutBrandScope()
                    ->where('brand_id', $stage->brand_id)
                    ->whereKey($entrantId)
                    ->update(['seed' => $index + 1]);
            }

            return $order;
        });
    }
}
