<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\PlanWriter;
use Konventix\Competition\Exceptions\StageDrawRefusedException;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Seeding\SignupField;

/**
 * Persists a stage's bracket from its format's plan (ADR-0019).
 *
 * The split is deliberate: generators are pure functions returning a plan,
 * and this is the only thing that writes. That keeps every correctness
 * property provable in memory (see `BracketMathematicsTest`), keeps the
 * transaction in one place, and means adding a format cannot introduce a new
 * way of touching the database.
 *
 * Refuses to run twice. Regenerating a bracket mid-tournament would orphan
 * results that already exist, so it is an explicit refusal rather than a
 * silent truncate — a destructive redraw should be a deliberate act with its
 * own Action, not a side effect of clicking generate again. That Action is
 * `RegenerateBracket` (design §7.5), and it is allowed until the first
 * recorded result.
 */
final class GenerateBracket
{
    use GuardsCompetitionLifecycle;

    public function __construct(
        private readonly FormatRegistry $formats,
        private readonly PlanWriter $writer,
        private readonly SignupField $field,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        return DB::transaction(function () use ($stage): BracketPlan {
            // §4: drawn once signup is closed — never while signup may still
            // add somebody the bracket would leave out.
            $this->guardDrawIsOpen($stage->competition_id);

            if ($stage->isGenerated()) {
                throw new StageDrawRefusedException(sprintf(
                    'Stage [%s] was already generated at %s; regenerating would orphan recorded results.',
                    $stage->id,
                    $stage->generated_at?->toIso8601String() ?? 'unknown',
                ));
            }

            $generator = $this->formats->get($stage->format);

            // Seed order is the entrants' seeding, with unseeded entrants
            // falling in behind in a stable order — a bracket must be
            // reproducible from the same field. `SignupField` owns the two
            // rules that decide it (design §6, §7.5) so that this and the
            // seed sources that *order* the same field cannot disagree about
            // who is in it.
            $plan = $generator->generate(
                $this->field->entrantIds($stage),
                array_merge($generator->defaults(), $stage->settings ?? []),
            );

            $this->writer->write($stage, $plan);

            $stage->forceFill(['generated_at' => now()])->save();

            return $plan;
        });
    }
}
