<?php

declare(strict_types=1);

namespace Konventix\Registration\Audience;

use Konventix\Platform\Pages\RegistrantAudience;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;

/**
 * Registration's answer to Platform's `RegistrantAudience` contract (Task
 * 20): a person is a registrant of a brand when they hold a confirmed
 * registration for any event of that brand. Confirmed only — a submitted,
 * approved, rejected or cancelled registration is not attendance.
 *
 * Bound in `RegistrationServiceProvider::registerBindings()`; Platform's
 * `Pages\PageAccess` resolves it lazily and fails closed without it. The
 * brand comes in explicitly because the caller runs on the brand host and
 * the answer is memoised per brand and viewer there; the query escapes the
 * ambient scope and filters on the brand it was asked about, the same
 * read-side shape the console uses (ADR-0015, `scopeForBrand`).
 *
 * Stateless — safe as a plain container resolution under Octane.
 */
final class RegistrationRegistrantAudience implements RegistrantAudience
{
    public function isRegistrantOf(string $brandId, string $userId): bool
    {
        return Registration::withoutBrandScope()
            ->forBrand($brandId)
            ->where('registrant_user_id', $userId)
            ->where('status', RegistrationStatus::Confirmed)
            ->exists();
    }
}
