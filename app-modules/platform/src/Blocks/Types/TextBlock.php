<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks\Types;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Data\Blocks\TextBlockData;

/**
 * Platform's own baseline block: heading plus markdown body. Also the
 * reference implementation for modules contributing their own types.
 */
class TextBlock implements BlockType
{
    public function key(): string
    {
        return 'text';
    }

    public function module(): string
    {
        return 'platform';
    }

    public function component(): string
    {
        return 'platform/blocks/TextBlock';
    }

    public function dataClass(): string
    {
        return TextBlockData::class;
    }
}
