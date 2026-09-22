<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Data\HubCardData;
use Konventix\Platform\Data\HubLinkData;
use Konventix\Platform\Data\HubPageData;
use Konventix\Platform\Observability\Metrics;

/**
 * Builds the live hub page (design §7.1) for the signed-in member's current
 * event: the operator's layout (design §6) applied to the cards and links
 * the viewer may see (design §5.2), each payload resolved once, here, and
 * only for a card the layout keeps.
 *
 * Its own class rather than controller code because two routes render it:
 * `/live` (`HubController`) and, when the operator opted in, `/` in place
 * of the brand's home page. Both hand over the request and get an Inertia
 * response back, so the rule for what the hub shows lives in one place.
 *
 * Every dependency is resolved through the container, never held across
 * requests: `ActiveEventContext` is scoped and this class is built per
 * request too (`.ai/rules/providers.md`).
 */
final class HubPage
{
    public function __construct(
        private readonly ActiveEventContext $context,
        private readonly HubRegistry $registry,
        private readonly HubLayoutResolver $layouts,
    ) {}

    public function render(Request $request): Response
    {
        $event = $this->context->current() ?? abort(404);
        $user = $request->user() ?? abort(403);
        $layout = $this->layouts->for($event->eventId);

        $cards = $this->registry->visibleCards($user)->keyBy('key');
        $links = $this->registry->visibleLinks($user)->keyBy('key');
        $orderedKeys = $this->orderedKeys($layout['cards'], [...$cards->keys()->all(), ...$links->keys()->all()]);

        $cardData = [];
        $linkData = [];

        foreach ($orderedKeys as $key) {
            if ($cards->has($key)) {
                $card = $cards[$key];
                $cardData[] = new HubCardData($card->key, $card->label, $card->component, ($card->payload)($event, $user));
            } elseif ($links->has($key)) {
                $link = $links[$key];
                $linkData[] = new HubLinkData($link->key, $link->label, $link->url === null ? null : ($link->url)($event, $user), $link->component, $link->icon);
            }
        }

        // D-HUB-04: a page view is the one operational fact the hub has —
        // whether admitted people actually reach it on the day. The brand
        // is the label, never the event or the person (`.ai/rules/observability.md`);
        // `currentOrFail()` because an active event exists only on a brand.
        app(Metrics::class)->increment('platform_hub_views_total', [
            'brand' => BrandSlugs::of(app(BrandContext::class)->currentOrFail()->id),
        ]);

        return Inertia::render('platform/live/Hub', [
            'hub' => new HubPageData($event, $this->context->all()->values()->all(), $cardData, $linkData),
        ]);
    }

    /**
     * Layout order first (visible only, unknown keys dropped), then everything
     * registered but not yet in the layout, in registry order (design §6).
     *
     * @param  list<array{key: string, visible: bool}>  $layout
     * @param  list<string>  $registered
     * @return list<string>
     */
    private function orderedKeys(array $layout, array $registered): array
    {
        $listed = array_column($layout, 'key');
        $ordered = [];

        foreach ($layout as $entry) {
            if ($entry['visible'] && in_array($entry['key'], $registered, true)) {
                $ordered[] = $entry['key'];
            }
        }

        foreach ($registered as $key) {
            if (! in_array($key, $listed, true)) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }
}
