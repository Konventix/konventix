<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\Date;
use Konventix\Commerce\Models\CheckedInAdmission;

/**
 * Maintains Commerce's check-in read model (ADR-0037). Takes scalars, never
 * the event object — the thin listener unpacks it, mirroring how
 * `PlaceOrderFromSettlementRequest` is kept free of Registration's classes.
 *
 * **Keeps the earliest check-in, not the latest.** The projection has to
 * converge under replay and out-of-order delivery (an offline device can
 * reconcile hours late, ADR-0033), so a second event for the same admission
 * must be idempotent rather than overwrite with a later timestamp. Whether a
 * refund is blocked never depends on *which* scan arrived first.
 */
final class ProjectCheckedInAdmission
{
    public function handle(
        string $admissionId,
        string $registrationId,
        string $brandId,
        string $scannedAt,
    ): CheckedInAdmission {
        $existing = CheckedInAdmission::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('admission_id', $admissionId)
            ->first();

        $scanned = Date::parse($scannedAt);

        if ($existing !== null) {
            if ($scanned->lessThan($existing->first_checked_in_at)) {
                $existing->forceFill(['first_checked_in_at' => $scanned])->save();
            }

            return $existing;
        }

        $row = new CheckedInAdmission([
            'admission_id' => $admissionId,
            'registration_id' => $registrationId,
            'first_checked_in_at' => $scanned,
        ]);
        // brand_id from the payload, not ambient context (ADR-0010).
        $row->forceFill(['brand_id' => $brandId])->save();

        return $row;
    }
}
