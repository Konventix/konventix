<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\PlanWriter;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use RuntimeException;

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
 * own Action, not a side effect of clicking generate again.
 */
final class GenerateBracket
{
    public function __construct(
        private readonly FormatRegistry $formats,
        private readonly PlanWriter $writer,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        return DB::transaction(function () use ($stage): BracketPlan {
            if ($stage->isGenerated()) {
                throw new RuntimeException(sprintf(
                    'Stage [%s] was already generated at %s; regenerating would orphan recorded results.',
                    $stage->id,
                    $stage->generated_at?->toIso8601String() ?? 'unknown',
                ));
            }

            $generator = $this->formats->get($stage->format);

            // Seed order is the entrants' seeding, with unseeded entrants
            // falling in behind in a stable order — a bracket must be
            // reproducible from the same field.
            $entrantIds = Entrant::withoutBrandScope()
                ->where('brand_id', $stage->brand_id)
                ->where('competition_id', $stage->competition_id)
                ->whereIn('state', [EntrantState::Registered->value, EntrantState::CheckedIn->value])
                ->orderByRaw('seed IS NULL')
                ->orderBy('seed')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $plan = $generator->generate(
                array_values(array_map(strval(...), $entrantIds)),
                array_merge($generator->defaults(), $stage->settings ?? []),
            );

            $this->writer->write($stage, $plan);

            $stage->forceFill(['generated_at' => now()])->save();

            return $plan;
        });
    }
}
