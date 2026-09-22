<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners\Metrics;

use Konventix\Community\Events\ShoutboxMessagePosted;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * The recorder behind `community_messages_posted_total` (ADR-0059).
 * `PostMessage` dispatches `ShoutboxMessagePosted` once per accepted line
 * and never for a refusal, so the counter counts lines on the wall rather
 * than presses of the send button.
 *
 * No try/catch: `Metrics` reports a storage failure instead of propagating
 * it. Scoped bindings are resolved inside the handler, never in a
 * constructor (`.ai/rules/listeners.md`).
 */
final class RecordShoutboxMetrics
{
    public function handle(ShoutboxMessagePosted $event): void
    {
        app(Metrics::class)->increment('community_messages_posted_total', [
            'brand' => BrandSlugs::of($event->brandId),
        ]);
    }
}
