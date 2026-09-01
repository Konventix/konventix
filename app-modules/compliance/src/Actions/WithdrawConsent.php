<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Events\ConsentWithdrawalRequested;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * Withdraws a previously granted Consent where the policy's lawful basis
 * permits self-service, and opens a DataSubjectRequest instead where it does
 * not (ADR-0050 §7/§8): Art. 7(3) makes withdrawing consent as easy as
 * giving it, but that only applies where the basis really is consent — a
 * contract or legitimate-interest basis makes leaving it something the
 * operator decides on, not a button.
 *
 * Withdrawal itself is a soft marker (`withdrawn_at`), never a delete: the
 * record that consent was once granted, and when it stopped being valid, is
 * itself the audit-relevant fact.
 */
final class WithdrawConsent
{
    public function __construct(private readonly OpenSubjectRequest $openSubjectRequest) {}

    public function __invoke(Consent $consent, ?CarbonInterface $withdrawnAt = null): Consent|DataSubjectRequest
    {
        $consent->loadMissing('policyVersion');

        if (! $consent->policyVersion->lawful_basis->allowsSelfServiceWithdrawal()) {
            // Idempotence: a second withdraw attempt while one is already
            // open for this consent must not open a duplicate — it hands
            // back the request already awaiting the operator's decision.
            $existing = DataSubjectRequest::query()
                ->where('consent_id', $consent->getKey())
                ->where('type', DataSubjectRequestType::ConsentWithdrawal)
                ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $request = ($this->openSubjectRequest)(
                SubjectRef::forUser($consent->subject_id),
                DataSubjectRequestType::ConsentWithdrawal,
                null,
                $consent->brand_id,
                $consent->getKey(),
            );

            event(new ConsentWithdrawalRequested(
                requestId: $request->getKey(),
                consentId: $consent->getKey(),
                subjectId: $consent->subject_id,
                policyId: $consent->policyVersion->policy_id,
            ));

            return $request;
        }

        return $this->withdrawImmediately($consent, $withdrawnAt);
    }

    /**
     * The withdrawal write itself: the soft marker plus ConsentWithdrawn.
     * Never re-enters the lawful-basis guard above — shared by the
     * self-service path once it has passed the guard, and by
     * ApproveConsentWithdrawal, where the operator's acceptance is already
     * the authority to withdraw regardless of basis.
     *
     * ApproveConsentWithdrawal calls this from the operator admin host,
     * where BrandScope hardens to zero rows (ADR-0029) — PolicyVersion is
     * brand-owned too, so loading it back has to escape the scope on this
     * relation explicitly (.ai/rules/app-modules-src.md) or it comes back
     * null even though the row exists.
     */
    public function withdrawImmediately(Consent $consent, ?CarbonInterface $withdrawnAt = null): Consent
    {
        return DB::transaction(function () use ($consent, $withdrawnAt): Consent {
            $withdrawnAt ??= now();

            $consent->forceFill(['withdrawn_at' => $withdrawnAt])->save();
            $consent->loadMissing(['policyVersion' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)]);

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
