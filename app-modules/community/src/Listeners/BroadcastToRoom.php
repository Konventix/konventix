<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Observability\Metrics;
use Konventix\Platform\Realtime\BrandBroadcasting;
use Throwable;

/**
 * Sends a shoutbox change to the room's private channel (design §8), and
 * says whether it arrived: `community_shoutbox_broadcasts_total{brand,
 * outcome}` with `sent` or `failed` (ADR-0059).
 *
 * The event classes describe the wire message (`broadcastOn()`,
 * `broadcastAs()`, `broadcastWith()`); this sends it the way the framework
 * sends a `ShouldBroadcastNow` event — `BroadcastEvent` dispatched now, in
 * this request — with one difference that is the reason this class
 * exists. The framework's `ShouldRescue` swallowed a failure inside
 * `BroadcastManager` where nothing could count it, so a Reverb outage
 * reached no dashboard and no alert. Here the rescue is Community's own:
 * the failure is `report()`ed exactly as `rescue()` did, and counted.
 *
 * **The send is signed for the room's brand, not the request's.** Reverb
 * credentials are per brand (ADR-0025) and `ResolveBrand` points
 * broadcasting at them on brand hosts only. A removal is made at the desk,
 * on the operator admin host, where no brand is resolved: without the
 * `useBrand()` below the tombstone went out with the empty host-level
 * `REVERB_APP_*` (and failed), or — under Octane — through a Pusher client
 * an earlier brand request on the same worker had cached, into another
 * brand's app where nobody listens (and was counted `sent`). On a brand
 * host the ambient brand is the room's and `useBrand()` returns without
 * touching anything. The switch is not undone afterwards: nothing else on
 * the admin host broadcasts without pointing at its own brand first, and
 * Octane hands every request a fresh copy of the config
 * (`CreateConfigurationSandbox`), so it cannot outlive this request.
 *
 * **A failure never reaches the caller.** The line or the removal is
 * already committed when this runs (`PostMessage` and `RemoveMessage`
 * dispatch through `DB::afterCommit()`), so a throw would tell a
 * participant their accepted line was refused. "Failure" is everything
 * inside the send: the brand lookup, the transport, and
 * `broadcastWith()`'s own reads of the line and its author's name. The
 * metric's brand label comes from the brand row the send already read, so
 * no cache round trip can throw after the send either.
 *
 * Registered first for both events in
 * `CommunityServiceProvider::registerListeners()`, so the room hears about
 * a line before anything else reacts to it — the order the framework's own
 * broadcast-before-listeners dispatch gave. Not gated on `community`: both
 * events come only from Community's own Actions, which a brand with the
 * module off cannot reach.
 */
final class BroadcastToRoom
{
    public function handle(ShoutboxMessagePosted|ShoutboxMessageRemoved $event): void
    {
        $brand = null;

        try {
            $brand = $this->roomBrand($event->brandId);

            if ($brand instanceof Brand) {
                app(BrandBroadcasting::class)->useBrand($brand);
            }

            app(Dispatcher::class)->dispatchNow(new BroadcastEvent($event));
            $outcome = 'sent';
        } catch (Throwable $e) {
            report($e);
            $outcome = 'failed';
        }

        app(Metrics::class)->increment('community_shoutbox_broadcasts_total', [
            'brand' => $brand instanceof Brand ? $brand->slug : BrandSlugs::UNKNOWN,
            'outcome' => $outcome,
        ]);
    }

    /**
     * The ambient brand when the request resolved the room's own (every
     * participant post), otherwise the row (a desk removal on the admin
     * host). `Brand` carries no brand scope, so the read works without one.
     */
    private function roomBrand(string $brandId): ?Brand
    {
        $ambient = app(BrandContext::class)->current();

        if ($ambient instanceof Brand && $ambient->getKey() === $brandId) {
            return $ambient;
        }

        return Brand::query()->find($brandId);
    }
}
