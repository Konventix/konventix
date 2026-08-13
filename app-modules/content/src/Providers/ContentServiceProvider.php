<?php

declare(strict_types=1);

namespace Konventix\Content\Providers;

use Konventix\Content\Blocks\Types\LatestNewsBlock;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Core module: always active for every brand, never gated.
 *
 * Whether *comments* are allowed is a per-brand setting inside this module,
 * not a Pennant activation gate — the distinction matters because a setting
 * has no bearing on which routes and listeners exist.
 */
class ContentServiceProvider extends ModuleServiceProvider
{
    protected bool $gateable = false;

    public static function moduleName(): string
    {
        return 'content';
    }

    /**
     * Contributed to **Platform's** registry, the same way Event contributes
     * its hero and countdown blocks. Content owns the block type; Platform
     * owns pages, storage and rendering — there is no second block system.
     */
    protected function registerBlockTypes(): void
    {
        $this->app->make(BlockRegistry::class)->register(new LatestNewsBlock);
    }
}
