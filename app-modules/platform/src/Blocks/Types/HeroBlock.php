<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks\Types;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Data\Blocks\HeroBlockData;

/**
 * Platform's opening/landing-hero block: heading, optional subheading, and
 * an optional call-to-action link and image. Needs no server-side
 * resolution — its stored data class already is the props shape.
 */
class HeroBlock implements BlockType
{
    public function key(): string
    {
        return 'hero';
    }

    public function module(): string
    {
        return 'platform';
    }

    public function component(): string
    {
        return 'platform/blocks/HeroBlock';
    }

    public function dataClass(): string
    {
        return HeroBlockData::class;
    }
}
