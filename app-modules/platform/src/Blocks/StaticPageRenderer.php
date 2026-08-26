<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

use Konventix\Platform\Models\StaticPage;

/**
 * Turns a static page's stored `{type, data}` block list into the
 * `{key, component, props}` shape `StaticPage.vue` consumes.
 *
 * Shared by `StaticPageController` (`/pages/{slug}`) and the `/` landing
 * route (host `routes/web.php`, which renders the brand's `home`-slug page
 * the same way), so both resolve blocks through the same registry gate and
 * the same optional server-side resolution hook ({@see ResolvesBlockProps}).
 *
 * Delegates the actual translation to {@see BlockCompositionRenderer}, kept
 * as its own class because two call sites depend on it and both take a
 * `StaticPage` rather than a raw block list.
 */
class StaticPageRenderer
{
    public function __construct(private readonly BlockCompositionRenderer $blocks) {}

    /**
     * @return list<array{key: string, component: string, props: array<string, mixed>}>
     */
    public function render(StaticPage $page): array
    {
        return $this->blocks->render($page->blocks?->toArray() ?? []);
    }
}
