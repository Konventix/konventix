<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * The formats a stage can run (design §7.1, D-CMP-16).
 *
 * The format lives on the **stage**, never on the competition:
 * `competitions.format` was 10a's single-flat-list shape and is gone. A
 * competition is one or more stages, and each stage names the engine that
 * runs it.
 *
 * Five of the six generate a bracket and are registered with `FormatRegistry`
 * (ADR-0019). `Leaderboard` deliberately is not: a leaderboard stage has **no
 * matches at all** (§7.2), only scored entries, so there is nothing for a
 * generator to plan and nothing for `GenerateBracket` to write. That is why
 * this enum exists beside the registry rather than being derived from it —
 * the registry answers "what can be drawn", this answers "what can a stage
 * be", and since the leaderboard those two sets are no longer the same.
 */
enum StageFormat: string
{
    case SingleElimination = 'single_elimination';
    case DoubleElimination = 'double_elimination';
    case RoundRobin = 'round_robin';
    case GroupStage = 'group_stage';
    case Swiss = 'swiss';

    /**
     * Scored entries instead of matches (§7.2): a best time, a points total
     * per heat, a jury average. Four of SXLAN's twenty-seven real tournaments
     * were this and were forced into a fake single-elimination bracket to fit
     * the old model.
     */
    case Leaderboard = 'leaderboard';

    /**
     * Whether this format draws matches. Everything except the leaderboard
     * does — which is the same question as "is there a `FormatGenerator` for
     * it", asked without reaching for the container.
     */
    public function producesMatches(): bool
    {
        return $this !== self::Leaderboard;
    }

    /** In the reader's locale (ADR-0068); the English is the catalog key. */
    public function label(): string
    {
        return match ($this) {
            self::SingleElimination => __('Single elimination'),
            self::DoubleElimination => __('Double elimination'),
            self::RoundRobin => __('Round robin'),
            self::GroupStage => __('Group stage'),
            self::Swiss => __('Swiss'),
            self::Leaderboard => __('Leaderboard'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
