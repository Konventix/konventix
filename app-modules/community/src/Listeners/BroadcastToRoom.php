<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
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
 * **A failure never reaches the caller.** The line or the removal is
 * already committed when this runs (`PostMessage` and `RemoveMessage`
 * dispatch through `DB::afterCommit()`), so a throw would tell a
 * participant their accepted line was refused. "Failure" is everything
 * inside the send: the transport, and `broadcastWith()`'s own reads of the
 * line and its author's name.
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
        try {
            app(Dispatcher::class)->dispatchNow(new BroadcastEvent($event));
            $outcome = 'sent';
        } catch (Throwable $e) {
            report($e);
            $outcome = 'failed';
        }

        app(Metrics::class)->increment('community_shoutbox_broadcasts_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'outcome' => $outcome,
        ]);
    }
}
