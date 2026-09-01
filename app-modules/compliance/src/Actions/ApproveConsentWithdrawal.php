<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * The operator's acceptance of a ConsentWithdrawal request (ADR-0050 §7/§8,
 * controller ruling 1): withdraws the named Consent through the same write
 * WithdrawConsent's self-service path uses, without re-entering the
 * lawful-basis guard — the operator's decision to accept *is* the authority
 * to withdraw here, regardless of the policy's basis — and closes the
 * request as Fulfilled.
 *
 * `Consent::withoutBrandScope()` is required: this action runs from the
 * operator admin host, where BrandScope hardens to zero rows (ADR-0029,
 * .ai/rules/app-modules-src.md).
 */
final class ApproveConsentWithdrawal
{
    public function __construct(private readonly WithdrawConsent $withdrawConsent) {}

    public function __invoke(DataSubjectRequest $request): DataSubjectRequest
    {
        if ($request->type !== DataSubjectRequestType::ConsentWithdrawal) {
            throw new InvalidArgumentException('Only a ConsentWithdrawal request can be accepted this way.');
        }

        if ($request->state === DataSubjectRequestState::Fulfilled) {
            return $request;
        }

        return DB::transaction(function () use ($request): DataSubjectRequest {
            /** @var Consent $consent */
            $consent = Consent::withoutBrandScope()->findOrFail($request->consent_id);

            if (! $consent->isWithdrawn()) {
                $this->withdrawConsent->withdrawImmediately($consent);
            }

            $request->forceFill([
                'state' => DataSubjectRequestState::Fulfilled,
                'closed_at' => now(),
            ])->save();

            return $request;
        });
    }
}
