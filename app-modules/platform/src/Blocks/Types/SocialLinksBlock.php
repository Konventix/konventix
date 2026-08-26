<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks\Types;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Data\Blocks\SocialLinksBlockData;

/**
 * A small set of social/profile links — built for a footer column, but a
 * block like any other, so it can be dropped onto a static page too.
 */
class SocialLinksBlock implements BlockType, HasBlockEditor
{
    public function key(): string
    {
        return 'social-links';
    }

    public function module(): string
    {
        return 'platform';
    }

    public function component(): string
    {
        return 'platform/blocks/SocialLinksBlock';
    }

    public function dataClass(): string
    {
        return SocialLinksBlockData::class;
    }

    public function label(): string
    {
        return 'Social links';
    }

    public function editorComponent(): string
    {
        return 'platform/blocks/editors/SocialLinksEditor';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(): array
    {
        return ['links' => [['network' => 'website', 'url' => '', 'label' => null]]];
    }
}
