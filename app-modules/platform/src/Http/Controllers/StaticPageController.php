<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Blocks\StaticPageRenderer;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Hub\HubPage;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Pages\PageAccess;

/**
 * Renders a brand's static page from its block list (ADR-0003 pattern: block
 * types come from the registry, so Platform renders blocks it has never heard
 * of). The block-list-to-props translation lives in {@see StaticPageRenderer}.
 *
 * Two routes, one rule: `/pages/{slug}` and the `/` landing route (host
 * `routes/web.php`, which renders a brand's `home`-slug page the same way)
 * both ask {@see PageAccess} whether this viewer may read the page. A
 * refusal on `/pages/{slug}` is a 404, never a login redirect — a redirect
 * would confirm the slug exists (the rule Content's audience set). On `/`
 * the refused viewer sees the Welcome fallback instead: the root of a site
 * always answers, and a 404 there would be a broken site, not a hidden page.
 *
 * A third route, `/home`, is the same landing page with one difference:
 * it never becomes the live hub. During an event whose operator turned the
 * switch on (D-HUB-04, design §7.3), `/` shows an admitted member the hub
 * page instead of the brand's home, and the logo in the topbar points at
 * `/home` so the plain page stays one click away. Whether the switch is on
 * travels on `ActiveEventData` itself (`hubReplacesHome`), so this
 * controller and the topbar decide it from one answer.
 */
class StaticPageController
{
    public function __construct(
        private readonly StaticPageRenderer $renderer,
        private readonly PageAccess $access,
        private readonly ActiveEventContext $context,
        private readonly HubPage $hub,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        /** @var StaticPage $page */
        $page = StaticPage::query()
            ->where('slug', $slug)
            ->published()
            ->firstOrFail();

        abort_unless($this->access->allows($page, $this->viewerId($request)), 404);

        return Inertia::render('StaticPage', [
            'title' => $page->title,
            'blocks' => $this->renderer->render($page),
        ]);
    }

    /**
     * The `/` landing route. If the current brand (BelongsToBrand's global
     * scope) has a published `home`-slug page this viewer may read, render
     * it through the same block pipeline as `/pages/{slug}`; otherwise fall
     * back to the minimal Welcome page — unless the live hub has taken the
     * root over for this viewer (D-HUB-04).
     */
    public function home(Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        // The admin host has no brand and therefore no home page: BrandScope
        // hardens the query below to nothing and the operator would land on
        // the empty Welcome page with no way into the console (hotfix
        // 2026-09-13). The console is the only thing this host is for, so
        // `/` goes there; `admin.dashboard` sends a guest on to login itself.
        if ($operator->hasOperator()) {
            return redirect()->route('admin.dashboard');
        }

        // D-HUB-04: for an admitted member whose event's operator turned the
        // switch on, `/` is the hub. A guest never reaches the context call,
        // so the public home page costs what it cost before.
        if ($request->user() !== null) {
            $active = $this->context->current();

            if ($active?->hubReplacesHome === true) {
                return $this->hub->render($request);
            }
        }

        return $this->brandHome($request);
    }

    /** The plain brand home, always — `/home` while the hub occupies `/`. */
    public function plainHome(Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        if ($operator->hasOperator()) {
            return redirect()->route('admin.dashboard');
        }

        return $this->brandHome($request);
    }

    private function brandHome(Request $request): Response
    {
        $home = StaticPage::query()
            ->where('slug', 'home')
            ->published()
            ->first();

        if ($home === null || ! $this->access->allows($home, $this->viewerId($request))) {
            return Inertia::render('Welcome');
        }

        return Inertia::render('StaticPage', [
            'title' => $home->title,
            'blocks' => $this->renderer->render($home),
        ]);
    }

    private function viewerId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
