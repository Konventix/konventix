<?php

declare(strict_types=1);

namespace Konventix\Content\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Content\Blocks\Types\LatestNewsBlock;
use Konventix\Content\Enums\Permission;
use Konventix\Content\Gdpr\ContentGdprHandler;
use Konventix\Content\Listeners\ProjectAuthor;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

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

    /**
     * The author read model (ADR-0004). Registered explicitly — modular event
     * discovery is off and must stay off (.ai/rules/listeners.md).
     */
    protected function registerListeners(): void
    {
        Event::listen(UserRegistered::class, ProjectAuthor::class);
        Event::listen(UserProfileUpdated::class, ProjectAuthor::class);
    }

    /**
     * Operator-console entries (ADR-0029). Moderation is a separate entry
     * rather than a tab on the editorial screen, because it is a separate
     * capability and often a separate person: a community moderator who may
     * hide an abusive comment has no business publishing the front page.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'content',
                label: 'Content',
                url: '/admin/content',
                icon: 'newspaper',
                order: 20,
                permission: Permission::ViewEntries->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'content',
                label: 'Comments',
                url: '/admin/content-comments',
                icon: 'message-square',
                order: 21,
                permission: Permission::ModerateComments->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * Comment authorship is anonymized; the bodies stay, because a thread
     * others replied to is not one person's to rewrite.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new ContentGdprHandler);
    }
}
