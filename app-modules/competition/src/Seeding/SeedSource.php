<?php

declare(strict_types=1);

namespace Konventix\Competition\Seeding;

use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Models\Stage;

/**
 * Where a stage's seed order comes from (design §7.3, §7.5).
 *
 * The generalisation §7.3 asks for: `GenerateBracket` used to resolve the
 * field itself, so "which entrants, in what order" was welded to "draw the
 * bracket". Splitting it out is what lets a second stage take its field from
 * the first one's standings instead of from signup, without a single caller
 * changing — `SeedStage` asks the registry for the source the stage's
 * `entry_source` names and writes whatever list comes back into
 * `entrants.seed`.
 *
 * ## The contract a `PreviousStageSeedSource` has to satisfy
 *
 * It takes the `Stage` being seeded and nothing else, because that is all
 * the later implementation can be sure of having: from the stage it reaches
 * its competition, its `sequence` (so the preceding stage is a lookup, not
 * an argument), that stage's `advance_count`, and that stage's `standings`
 * rows, which `CalculateStandings` has already written and which carry
 * `placement` and `group`. Handing the source an explicit entrant list here
 * would have made exactly that implementation impossible without changing
 * the signature — which is the thing §7.3 is trying to avoid.
 *
 * ## Ordering
 *
 * The returned list **is** the seed order: index 0 becomes seed 1. It
 * contains only entrants that may actually be drawn, and it may be shorter
 * than the field — a source that advances the best eight of sixteen returns
 * eight.
 */
interface SeedSource
{
    /**
     * The `entry_source` this source serves. `SeedSourceRegistry` keys on it,
     * so registering a source is the whole of plugging one in.
     */
    public function entrySource(): StageEntrySource;

    /**
     * @return list<string> entrant ULIDs in seed order; index 0 is seed 1
     */
    public function seedOrder(Stage $stage): array;
}
