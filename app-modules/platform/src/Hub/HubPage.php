<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Data\HubCardData;
use Konventix\Platform\Data\HubLinkData;
use Konventix\Platform\Data\HubPageData;
use Konventix\Platform\Observability\Metrics;
use Throwable;

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
 *
 * Every payload and URL closure runs behind a guard — see `drop()`. One
 * module's failure costs its own card, never the page. A URL closure that
 * answers null is not a failure: the link withdraws itself and the rest of
 * the row renders (`HubLink`).
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

        $brand = BrandSlugs::of(app(BrandContext::class)->currentOrFail()->id);

        foreach ($orderedKeys as $key) {
            $item = $cards[$key] ?? $links[$key] ?? null;

            if ($item === null) {
                continue;
            }

            try {
                if ($item instanceof HubCard) {
                    $cardData[] = new HubCardData($item->key, $item->label, $item->component, ($item->payload)($event, $user));

                    continue;
                }

                $url = $item->url === null ? null : ($item->url)($event, $user);

                // A URL closure that answers null withdraws its own link
                // (`HubLink`): the module looked at this member and found
                // nothing to point at. A link rendering through a component
                // has no URL and is not withdrawing anything.
                if ($url === null && $item->url !== null) {
                    continue;
                }

                $linkData[] = new HubLinkData($item->key, $item->label, $url, $item->component, $item->icon);
            } catch (Throwable $exception) {
                $this->drop($exception, $item, $brand);
            }
        }

        // D-HUB-04: a page view is the one operational fact the hub has —
        // whether admitted people actually reach it on the day. The brand
        // is the label, never the event or the person (`.ai/rules/observability.md`);
        // `currentOrFail()` above because an active event exists only on a brand.
        app(Metrics::class)->increment('platform_hub_views_total', ['brand' => $brand]);

        return Inertia::render('platform/live/Hub', [
            'hub' => new HubPageData($event, $this->context->all()->values()->all(), $cardData, $linkData),
        ]);
    }

    /**
     * Contain one contributor's failure: drop the entry, record it, and let
     * the rest of the page render.
     *
     * Platform cannot vet a foreign module's payload — it does not know what
     * the closure reads or what it may throw, and by ADR-0003 it must not
     * care. So the shell holds the guarantee instead: a brand whose entrance
     * signing key has been retired makes `IssueAdmissionToken` refuse to sign
     * and `admission.my-ticket` throw, and without this the event-day page
     * would 500 for everyone on that brand — including the `Hilfe` link that
     * is the participant's only way to say so.
     *
     * A dropped card is not a blank card: the page renders without it, the
     * same way a component name nothing ships under renders nothing. The
     * counter is what tells the operator it happened, per module rather than
     * per key — the key is in the log line, and ids and free text stay out of
     * labels (`.ai/rules/observability.md`).
     *
     * No alert rule of its own, deliberately: `Log::error` already trips
     * `KonventixErrorsLogged`, so the page is paged for. The counter answers
     * the next question — which module, on which brand, how often — which is
     * what the dashboard panel is for.
     */
    private function drop(Throwable $exception, HubCard|HubLink $item, string $brand): void
    {
        Log::error('A live-hub contribution failed and was dropped (D-HUB-05).', [
            'module' => $item->module,
            'key' => $item->key,
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);

        app(Metrics::class)->increment('platform_hub_card_failures_total', [
            'brand' => $brand,
            'module' => $item->module,
        ]);
    }

    /**
     * Layout order first (visible only, unknown keys dropped), then everything
     * registered but not yet in the layout, in registry order (design §6).
     *
     * Each key appears **once**, first position wins. `UpdateHubLayoutData`
     * validates the entries one by one and has nothing to say about the list
     * as a whole, so a hand-built PUT can save the same key twice — and the
     * page would then render that card twice and run its payload twice, which
     * for the ticket card means signing a second token nobody asked for. The
     * layout is an order, not a bag, so the duplicate is dropped here rather
     * than trusted.
     *
     * @param  list<array{key: string, visible: bool}>  $layout
     * @param  list<string>  $registered
     * @return list<string>
     */
    private function orderedKeys(array $layout, array $registered): array
    {
        $listed = array_column($layout, 'key');
        $ordered = [];
        $seen = [];

        foreach ($layout as $entry) {
            if ($entry['visible'] && in_array($entry['key'], $registered, true) && ! isset($seen[$entry['key']])) {
                $ordered[] = $entry['key'];
                $seen[$entry['key']] = true;
            }
        }

        foreach ($registered as $key) {
            if (! in_array($key, $listed, true) && ! isset($seen[$key])) {
                $ordered[] = $key;
                $seen[$key] = true;
            }
        }

        return $ordered;
    }
}
