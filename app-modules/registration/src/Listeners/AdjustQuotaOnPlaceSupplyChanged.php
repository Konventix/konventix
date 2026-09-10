<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Registration\Actions\Concerns\ConsumesQuotas;
use Konventix\Registration\Actions\Concerns\ReleasesQuotas;
use Konventix\Registration\Models\Quota;
use Konventix\Resources\Events\PlaceSupplyChanged;

/**
 * The consumer half of design §12b/#282: a seat category's ceiling is a
 * Registration `Quota`, so blocking a place or reserving one for the
 * organisation must consume a unit exactly as a sale would, and giving
 * either back must release one — which, through the existing
 * `QuotaReleased` → `PromoteOnQuotaReleased` chain, offers the freed unit
 * to the waitlist without anything new here.
 *
 * `consumeQuota()`/`releaseQuota()` are `ConsumesQuotas`/`ReleasesQuotas`'
 * own per-quota bodies (the same lock, the same `QuotaExhausted`/
 * `QuotaReleased` emission `SubmitRegistration` and `CancelRegistration`
 * already rely on) — this listener only finds the one quota a category
 * names and reuses them, with no capacity pre-check: a block is never
 * refused for exhaustion, it just makes the ledger honest.
 *
 * Brand comes from the payload, never from ambient context (`BrandContext`
 * may hold nothing — the emitting Actions run on the admin host — or may
 * hold a brand a replayed event no longer belongs to), and every quota
 * lookup escapes `BrandScope` explicitly: this listener can run inside the
 * same admin-host request that emitted the event, where the scope hardens
 * to zero rows (ADR-0029, `.ai/rules/app-modules-src.md`).
 *
 * Idempotent under a replayed event via `quota_supply_change_receipts`, a
 * dedup ledger keyed on the event's own `changeId`: the payload carries an
 * aggregate place count rather than row ids, so — unlike a booking or a
 * payment, whose own state already says "already applied" — there is no
 * row here to read that fact off, and this table exists only to hold it.
 * A no-op, not even a receipt, when no quota names the category at all.
 */
final class AdjustQuotaOnPlaceSupplyChanged
{
    use ConsumesQuotas;
    use ReleasesQuotas;

    public function handle(PlaceSupplyChanged $event): void
    {
        DB::transaction(function () use ($event): void {
            /** @var Collection<int, Quota> $quotas */
            $quotas = Quota::withoutBrandScope()
                ->where('brand_id', $event->brandId)
                ->where('resource_type_id', $event->resourceTypeId)
                ->lockForUpdate()
                ->get();

            if ($quotas->isEmpty()) {
                return;
            }

            $alreadyProcessed = DB::table('quota_supply_change_receipts')
                ->where('id', $event->changeId)
                ->exists();

            if ($alreadyProcessed) {
                return;
            }

            DB::table('quota_supply_change_receipts')->insert([
                'id' => $event->changeId,
                'quota_id' => $quotas->first()->getKey(),
                'created_at' => now(),
            ]);

            foreach ($quotas as $quota) {
                match ($event->direction) {
                    'consumed' => $this->consumeQuota($quota, $event->places, $event->eventId),
                    'released' => $this->releaseQuota($quota, $event->places),
                    default => throw new InvalidArgumentException(sprintf(
                        'Unknown PlaceSupplyChanged direction [%s].',
                        $event->direction,
                    )),
                };
            }
        });
    }
}
