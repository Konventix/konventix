<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\Admission;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;

/**
 * Backfills `admissions.buyer_user_id` for rows written before that column
 * existed (design doc §13, 2026-09-11 amendment "a registration-derived
 * admission is anonymous today"). Registered in
 * `AdmissionServiceProvider::registerListeners()`.
 *
 * The `whereNull('buyer_user_id')` guard is the whole contract: this never
 * overwrites a value `IssueAdmissionsForRegistration` already set, so
 * running `registration:announce-ownership` any number of times, before or
 * after live traffic has filled some rows in, changes nothing on a row
 * that already has a buyer.
 *
 * `Konventix\Registration\Events\RegistrationOwnershipAnnounced` is this
 * module's second sanctioned import from Registration's `Events\`
 * namespace (ADR-0004), alongside `RegistrationConfirmed` and
 * `RegistrationAmended`; `tests/RegistrationCommerceImportBoundaryTest.php`
 * already allows any class from that namespace.
 */
final class FillBuyerOnRegistrationOwnershipAnnounced
{
    public function handle(RegistrationOwnershipAnnounced $event): void
    {
        Admission::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('registration_id', $event->registrationId)
            ->whereNull('buyer_user_id')
            ->update(['buyer_user_id' => $event->registrantUserId]);
    }
}
