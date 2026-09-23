<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin\Concerns;

use Konventix\Admission\Models\EventSummary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Keeps Admission's console to the admin host's own brands (ADR-0029,
 * attendee-list design §5). A copy of Competition's concern, not an import:
 * a module never uses another module's non-event class (ADR-0004).
 *
 * Every console query here escapes `BrandScope`, because the scope hardens to
 * zero rows on the admin host. That also means nothing *else* stops a console
 * account of operator Y that knows an event ULID of operator X from reading
 * X's attendees: the capability was checked against Y's team, and this is the
 * check that the row is Y's at all.
 *
 * A row of another operator answers **404, never 403**: the console does not
 * confirm that somebody else's event exists. The capability check stays first
 * in every controller method, so an account without it reads 403 whatever it
 * asks for.
 *
 * Unlike Competition's copy there is no Pennant filter: Admission is Core
 * (`$gateable = false`) and cannot be switched off for a brand.
 */
trait ScopesToOperator
{
    /**
     * The event as Admission knows it, if its brand is the admin host
     * operator's; 404 otherwise, and 404 for an event this module has no
     * summary of (it cannot list what it cannot name or scope).
     */
    private function findEventSummary(string $event): EventSummary
    {
        $summary = EventSummary::withoutBrandScope()->where('event_id', $event)->first();

        abort_if($summary === null, 404);

        $this->ensureOperatorOwns($summary->brand_id);

        return $summary;
    }

    /**
     * 404 unless the brand belongs to the operator whose admin host this is.
     * A console request without a resolved operator cannot reach here past
     * `EnsureOperatorHost`, so its absence is a 404 like any stranger's.
     *
     * Asked afresh on every call and never kept on `$this`: a controller
     * instance can outlive its request under Octane.
     */
    private function ensureOperatorOwns(string $brandId): void
    {
        $operator = app(OperatorContext::class)->current() ?? abort(404);

        abort_unless(
            Brand::query()->whereKey($brandId)->where('operator_id', $operator->getKey())->exists(),
            404,
        );
    }
}
