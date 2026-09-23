<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Where a stage's entrants come from (design §7.3, D-CMP-16).
 *
 * Multi-stage competitions are **modelled now and built later**: a real
 * tournament is rarely one bracket — a league before a double-elimination
 * final, Trackmania qualifiers into a final — and entrants have to *flow*
 * between the stages. Settling the column now means the single-stage build
 * does not have to be undone when the second stage arrives.
 *
 * The value picks the stage's `SeedSource` out of `SeedSourceRegistry`, which
 * is the seam the later slice plugs `PreviousStageSeedSource` into. Nothing
 * is registered for `PreviousStage` in this slice and the registry says so by
 * name rather than seeding an empty field.
 */
enum StageEntrySource: string
{
    /** The competition's own ready entrants (design §6, §7.5). */
    case Signup = 'signup';

    /**
     * The `advance_count` best of the preceding stage's standings. Modelled,
     * not built — see the registry.
     */
    case PreviousStage = 'previous_stage';

    /** In the reader's locale (ADR-0068); the English is the catalog key. */
    public function label(): string
    {
        return match ($this) {
            self::Signup => __('The entrants who signed up'),
            self::PreviousStage => __('Who advanced from the previous stage'),
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
