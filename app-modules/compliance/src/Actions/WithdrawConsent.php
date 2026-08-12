<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Models\Consent;

/**
 * Withdraws a previously granted Consent and emits ConsentWithdrawn.
 *
 * Withdrawal is a soft marker (`withdrawn_at`), never a delete: the record
 * that consent was once granted, and when it stopped being valid, is itself
 * the audit-relevant fact.
 */
final class WithdrawConsent
{
    public function __invoke(Consent $consent, ?CarbonInterface $withdrawnAt = null): Consent
    {
        return DB::transaction(function () use ($consent, $withdrawnAt): Consent {
            $withdrawnAt ??= now();

            $consent->forceFill(['withdrawn_at' => $withdrawnAt])->save();
            $consent->loadMissing('policyVersion');

            event(new ConsentWithdrawn(
                consentId: $consent->id,
                subjectId: $consent->subject_id,
                policyId: $consent->policyVersion->policy_id,
                withdrawnAt: $withdrawnAt->toIso8601String(),
            ));

            return $consent;
        });
    }
}
