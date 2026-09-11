<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Models\Plan;
use Konventix\Resources\Plans\PlanCanonicalJson;

/**
 * The plan document in canonical JSON (design §6.4) — the console download
 * and `resources:plan-export` both hand out exactly this string. No
 * geometry is derived here; the document is the export.
 */
final class ExportPlan
{
    public function handle(Plan $plan): string
    {
        return PlanCanonicalJson::encode($plan->document);
    }
}
