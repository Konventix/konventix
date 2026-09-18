<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceClaim;

/**
 * The per-ticket picker's one authorisation (#292 §6.4): the admission's
 * row in this module's read model, live and not revoked, whose claim the
 * signed-in account may act on — as the claim's registrant (the manager)
 * or as the admission's holder. Null for anybody else, which the
 * controllers turn into a 404 so a stranger learns nothing, the way
 * `FindSeatClaimForRegistrant` answers.
 *
 * A staff admission has no registration and therefore never a claim: null.
 *
 * @return array{claim: ResourceClaim, admission: ResourceAdmission}|null
 */
final class FindSeatClaimForAdmission
{
    public function handle(string $admissionId, string $userId): ?array
    {
        $admission = ResourceAdmission::query()->find($admissionId);

        if ($admission === null || $admission->isRevoked() || $admission->registration_id === null) {
            return null;
        }

        $claim = ResourceClaim::query()
            ->where('registration_id', $admission->registration_id)
            ->where('state', '!=', ClaimState::Released)
            ->orderByDesc('created_at')
            ->first();

        if ($claim === null) {
            return null;
        }

        $isManager = $claim->registrant_user_id === $userId;
        $isHolder = $admission->holder_user_id === $userId;

        if (! $isManager && ! $isHolder) {
            return null;
        }

        return ['claim' => $claim, 'admission' => $admission];
    }
}
