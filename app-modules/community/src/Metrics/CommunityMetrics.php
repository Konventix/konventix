<?php

declare(strict_types=1);

namespace Konventix\Community\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Community's entries in the metric catalogue (ADR-0059), both about the
 * event shoutbox:
 *
 * - `community_messages_posted_total{brand}` — lines accepted, recorded by
 *   `Listeners\Metrics\RecordShoutboxMetrics` on this module's own
 *   `ShoutboxMessagePosted`. On event day it should move; flat while the hub
 *   is being viewed means nobody can post.
 * - `community_shoutbox_broadcasts_total{brand,outcome}` — `sent` or
 *   `failed`, recorded by `Listeners\BroadcastToRoom` where the broadcast is
 *   sent. A failed broadcast is swallowed so that it cannot fail an accepted
 *   write, which makes it a silent failure by construction: this counter,
 *   its panel and `KonventixShoutboxBroadcastFailing` are how anybody learns
 *   the wall stopped being live.
 *
 * `brand` is the slug and `outcome` a two-value set: no room, event or user
 * ids as labels (`.ai/rules/observability.md`).
 */
final class CommunityMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('community_messages_posted_total', MetricKind::Counter, 'Shoutbox lines accepted.', ['brand']),
            new MetricDefinition('community_shoutbox_broadcasts_total', MetricKind::Counter, 'Shoutbox broadcasts to a room channel, by outcome: sent, failed.', ['brand', 'outcome']),
        ];
    }
}
