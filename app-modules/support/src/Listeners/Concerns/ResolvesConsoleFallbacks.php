<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners\Concerns;

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;

/**
 * What a console-filed report has to stand in for, in one place.
 *
 * A report filed on the operator console has `filed_on_brand_id = null`
 * (ADR-0063) — correct on the row, and a problem for exactly one consumer:
 * Messaging is brand-scoped down to its delivery log, its suppression list
 * and its hourly quota, and `messaging_messages.brand_id` is not nullable.
 * So the *notice about* a brandless report is sent on the operator's oldest
 * brand, while the report itself stays brandless everywhere it is read.
 *
 * The operator is the one the request resolved (`OperatorContext`, scoped —
 * hence resolved inside the method and never in a constructor,
 * `.ai/rules/listeners.md`), falling back to the operator behind the oldest
 * brand, because an instance runs as one operator (ADR-0001). `Brand` is
 * the one foreign model the boundary allows a module to read (ADR-0004),
 * which is why neither helper goes near Platform's `Operator`.
 */
trait ResolvesConsoleFallbacks
{
    protected function consoleOperatorId(): ?string
    {
        $fromRequest = app(OperatorContext::class)->id();

        if ($fromRequest !== null) {
            return $fromRequest;
        }

        $operatorId = Brand::query()->oldest('created_at')->value('operator_id');

        return is_string($operatorId) ? $operatorId : null;
    }

    /**
     * The brand a message about a brandless report is booked on. Null when
     * the operator has no brand at all, which is a fresh install and not a
     * state any report can be filed in.
     */
    protected function messagingBrandId(?string $reportBrandId, ?string $operatorId): ?string
    {
        if ($reportBrandId !== null) {
            return $reportBrandId;
        }

        if ($operatorId === null) {
            return null;
        }

        $brandId = Brand::query()
            ->where('operator_id', $operatorId)
            ->oldest('created_at')
            ->value('id');

        return is_string($brandId) ? $brandId : null;
    }
}
