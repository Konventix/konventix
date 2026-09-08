<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\WaitlistOfferExpired;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * Expires lapsed offers (D-REG-12): the held quota units go back, the
 * entry moves to Expired, and `QuotaReleased` fires per quota — which is
 * exactly what triggers promotion of the next queued entry, so the queue
 * drains itself without a separate re-promotion pass.
 *
 * Runs from the scheduler (`registration:expire-waitlist-offers`, every
 * fifteen minutes) across all brands — `withoutBrandScope` deliberately.
 */
final class ExpireWaitlistOffers
{
    public function handle(): int
    {
        $expired = 0;

        /** @var list<WaitlistEntry> $entries */
        $entries = WaitlistEntry::withoutBrandScope()
            ->where('state', WaitlistState::Offered)
            ->where('offer_expires_at', '<=', now())
            ->get()
            ->all();

        foreach ($entries as $entry) {
            DB::transaction(function () use ($entry): void {
                // The `quotas()` relation escapes BrandScope on its own
                // query, not just the type's (#236 fix round 1): this runs
                // from a console command with no brand in context, where a
                // relation call would read an empty set and release nothing.
                $quotas = $entry->registrationType()->withoutGlobalScopes()->firstOrFail()
                    ->quotas()->withoutGlobalScope(BrandScope::class)->lockForUpdate()->get();

                $entry->forceFill(['state' => WaitlistState::Expired])->save();

                event(new WaitlistOfferExpired(
                    waitlistEntryId: $entry->id,
                    registrationTypeId: $entry->registration_type_id,
                    brandId: $entry->brand_id,
                    expiredAt: now()->toIso8601String(),
                ));

                foreach ($quotas as $quota) {
                    /** @var Quota $quota */
                    $quota->release(1);

                    event(new QuotaReleased(
                        quotaId: $quota->id,
                        releasedUnits: 1,
                        releasedAt: now()->toIso8601String(),
                    ));
                }
            });

            $expired++;
        }

        return $expired;
    }
}
