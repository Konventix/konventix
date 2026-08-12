<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Blocks\StaticPageRenderer;
use Konventix\Platform\Models\StaticPage;

/**
 * Renders a brand's static page from its block list (ADR-0003 pattern: block
 * types come from the registry, so Platform renders blocks it has never heard
 * of). The block-list-to-props translation lives in {@see StaticPageRenderer},
 * shared with the `/` landing route (host `routes/web.php`), which renders a
 * brand's `home`-slug page the same way.
 */
class StaticPageController
{
    public function __construct(private readonly StaticPageRenderer $renderer) {}

    public function show(string $slug): Response
    {
        /** @var StaticPage $page */
        $page = StaticPage::query()
            ->where('slug', $slug)
            ->whereNotNull('published_at')
            ->firstOrFail();

        return Inertia::render('StaticPage', [
            'title' => $page->title,
            'blocks' => $this->renderer->render($page),
        ]);
    }
}
