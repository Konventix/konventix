<?php

declare(strict_types=1);

namespace Konventix\Content\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Content\Blocks\Types\LatestNewsBlock;
use Konventix\Content\Data\AnnouncementsCardData;
use Konventix\Content\Enums\Permission;
use Konventix\Content\Gdpr\ContentGdprHandler;
use Konventix\Content\Hub\AnnouncementsCard;
use Konventix\Content\Listeners\ProjectAuthor;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Permissions\DefaultRole;

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
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of('content.editor', 'Content Editor', ...Permission::cases()),
        ];
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
     * The reader's entry in the brand topbar, and the operator-console
     * entries (ADR-0029). Moderation is a separate console entry
     * rather than a tab on the editorial screen, because it is a separate
     * capability and often a separate person: a community moderator who may
     * hide an abusive comment has no business publishing the front page.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            // The reader's entry point. No `permission`: `/news` is open to
            // anyone, and the entry has to be visible to a visitor with no
            // account for the page to be reachable at all.
            new NavigationItem(
                module: 'content',
                label: 'News',
                url: '/news',
                icon: 'newspaper',
                order: 20,
                area: NavigationItem::AREA_BRAND,
            ),
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
     * What the organizers said today, on the live hub (D-HUB-05). Boot-time
     * and unconditional, like the navigation entries above — `HubRegistry`
     * gates by module activation and viewer capability when the hub renders
     * (ADR-0003).
     *
     * No `permission`: `/news` is open to anyone, so a card that teases it
     * has nothing narrower to ask for.
     *
     * `static fn` with `AnnouncementsCard` resolved inside it: the registry
     * is a singleton that outlives the request under Octane, and a closure
     * capturing `$this` would pin this provider — and its container — in the
     * worker for good (`.ai/rules/providers.md`).
     */
    protected function registerHub(): void
    {
        $this->app->make(HubRegistry::class)->add(new HubCard(
            module: 'content',
            key: 'content.announcements',
            label: 'Ankündigungen',
            component: 'content/hub/AnnouncementsCard',
            payload: static fn (ActiveEventData $event, Authenticatable $user): AnnouncementsCardData => app(AnnouncementsCard::class)($event, $user),
            order: 20,
        ));
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
