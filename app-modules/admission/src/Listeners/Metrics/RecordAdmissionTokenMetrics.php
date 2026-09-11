<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners\Metrics;

use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * `admission_tokens_superseded_total` (design doc §9): the one rejection
 * reason among `CheckInRejected`'s nine that tells an operator whether
 * §5's new enforcement is turning away real people — not the door's whole
 * rejection tally, which is already readable from `check_ins`.
 *
 * Scoped bindings are resolved inside handle(), never the constructor
 * (`.ai/rules/listeners.md`) — this listener has none to resolve, but
 * `Metrics` itself is fetched via `app()` here rather than injected for
 * the same reason every other `Listeners/Metrics` recorder in this
 * codebase does.
 */
final class RecordAdmissionTokenMetrics
{
    public function handle(CheckInRejected $event): void
    {
        if ($event->reasonCode !== CheckInRejectionReason::NonceSuperseded->value) {
            return;
        }

        app(Metrics::class)->increment('admission_tokens_superseded_total', [
            'brand' => BrandSlugs::of($event->brandId),
        ]);
    }
}
