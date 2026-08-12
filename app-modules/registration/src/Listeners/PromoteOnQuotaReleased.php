<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Registration\Actions\PromoteFromWaitlist;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Models\Quota;

/**
 * Same-module trigger (D-REG-12): a released quota unit — cancellation,
 * rejection, or an expired offer — promotes the oldest queued waitlist
 * entry whose type can now be fully satisfied.
 *
 * The quota row supplies the brand id for the cross-context lookup
 * (`QuotaReleased`'s payload carries no brand — pre-waitlist shape kept
 * for compatibility; the row is authoritative anyway).
 */
final class PromoteOnQuotaReleased
{
    public function __construct(
        private readonly PromoteFromWaitlist $promoteFromWaitlist,
    ) {}

    public function handle(QuotaReleased $event): void
    {
        $quota = Quota::withoutBrandScope()->find($event->quotaId);

        if ($quota === null) {
            return;
        }

        $this->promoteFromWaitlist->handle($event->quotaId, $quota->brand_id);
    }
}
