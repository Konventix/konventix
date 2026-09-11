<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners\Metrics;

use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Konventix\Resources\Events\PlanSaved;
use Konventix\Resources\Events\PlanSaveRefused;

/**
 * Plan saves by outcome (ADR-0059, design §9.6): `saved` from the contract
 * event, the three refusals from the internal one. A 422 the request's
 * Data rules produce before `SavePlan` is reached is a shape error the
 * HTTP counters already see (`http_requests_total{status="422"}`) and is
 * deliberately not counted twice here.
 *
 * Scoped bindings are resolved inside handle() (.ai/rules/listeners.md).
 */
final class RecordPlanMetrics
{
    public function handle(PlanSaved|PlanSaveRefused $event): void
    {
        $outcome = $event instanceof PlanSaved ? 'saved' : $event->outcome;

        app(Metrics::class)->increment('resource_plan_saves_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'outcome' => $outcome,
        ]);
    }
}
