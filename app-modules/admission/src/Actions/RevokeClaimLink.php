<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Konventix\Admission\Models\AdmissionClaimLink;

/**
 * Withdraws a live claim link (#292 §4.1): the manager changed their mind,
 * or sent it to the wrong address. Idempotent on a link that is already
 * claimed, revoked or expired — nothing to withdraw, nothing to report.
 */
final class RevokeClaimLink
{
    public function handle(AdmissionClaimLink $link): void
    {
        if (! $link->isClaimable()) {
            return;
        }

        $link->forceFill(['revoked_at' => now()])->save();
    }
}
