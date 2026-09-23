<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Models\Stage;

/**
 * Redraws a stage that has not been played yet (D-CMP-20, design §7.5).
 *
 * `GenerateBracket` refuses to run twice, and that refusal stays: generating
 * is not idempotent and a second click must not silently truncate a
 * tournament. What §7.5 adds is the deliberate act — a desk that spots a
 * wrong seeding before the first match fixes it here, instead of running the
 * whole competition off a draw it knows is wrong.
 *
 * It is `ResetStage` followed by `GenerateBracket`, in one transaction: the
 * reset owns the line (the first recorded result, not `generated_at`) and the
 * deleting, the generator owns the drawing.
 *
 * **Seeds are not touched.** That is the point of the split from `SeedStage`:
 * a desk that dragged sixteen teams into the order it wanted and then
 * regenerates gets the same order back, not a fresh shuffle. Re-seeding is
 * the other button.
 */
final class RegenerateBracket
{
    public function __construct(
        private readonly ResetStage $reset,
        private readonly GenerateBracket $generate,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        return DB::transaction(fn (): BracketPlan => $this->generate->handle($this->reset->handle($stage)));
    }
}
