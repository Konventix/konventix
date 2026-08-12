<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks\Types;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Data\Blocks\CardsBlockData;

/**
 * Platform's key-details-grid block: a list of title/body/icon cards, used
 * for things like "Date & Time" / "Venue" / "Registration" summaries.
 */
class CardsBlock implements BlockType
{
    public function key(): string
    {
        return 'cards';
    }

    public function module(): string
    {
        return 'platform';
    }

    public function component(): string
    {
        return 'platform/blocks/CardsBlock';
    }

    public function dataClass(): string
    {
        return CardsBlockData::class;
    }
}
