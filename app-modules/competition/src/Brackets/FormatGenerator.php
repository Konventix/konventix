<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets;

use Konventix\Competition\Brackets\Plan\BracketPlan;

/**
 * Turns a seeded list of entrants into a bracket plan (ADR-0019's absorbed
 * contract, adapted).
 *
 * Deliberately narrower than LanBrackets' original, which took a
 * `CompetitionStage` model and wrote rows itself. Taking ids and returning a
 * plan makes every generator a pure function — testable without a database,
 * and provable against the mathematics documented in `SPEC.md` rather than
 * against fixtures.
 */
interface FormatGenerator
{
    /**
     * @param  list<string>  $entrantIds  in seed order: index 0 is seed 1
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $entrantIds, array $settings = []): BracketPlan;

    /**
     * The ruleset keys this format honours, with their defaults. Anything
     * else in `settings` is ignored rather than rejected, so a stage carrying
     * settings for a format it was switched away from still generates.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    public function key(): string;
}
