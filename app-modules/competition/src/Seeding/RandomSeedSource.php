<?php

declare(strict_types=1);

namespace Konventix\Competition\Seeding;

use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Models\Stage;

/**
 * The default seed order: random (D-CMP-20, design §7.5).
 *
 * Random rather than by signup time, which is what an unseeded field falls
 * back to today: the first two people to click enter should not meet in the
 * final because they were quick, and a LAN has no rating to seed from. The
 * desk overrides it by dragging the list, which is `ManualSeedSource`, and
 * may re-shuffle as often as it likes until the first result lands.
 *
 * Deliberately not seeded from anything reproducible. A "random" order a
 * caller could predict would be worth less than signup order, because it
 * would look fair and not be.
 */
final class RandomSeedSource implements SeedSource
{
    public function __construct(private readonly SignupField $field) {}

    public function entrySource(): StageEntrySource
    {
        return StageEntrySource::Signup;
    }

    /**
     * @return list<string>
     */
    public function seedOrder(Stage $stage): array
    {
        $entrantIds = $this->field->entrantIds($stage);

        shuffle($entrantIds);

        return $entrantIds;
    }
}
