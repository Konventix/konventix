<?php

declare(strict_types=1);

namespace Konventix\Platform\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Models\HubAccess;

/**
 * Platform's contribution to GDPR export and deletion (design §9).
 *
 * The one thing Platform holds *about* a person rather than about a brand
 * is the `hub_access` projection: which running events they were admitted
 * to, and whether they were on site. It is exported, and deleted in both
 * modes — a projection about a person who is gone means nothing, records
 * no obligation of the operator's (Admission keeps the admission itself
 * for that), and would be rebuilt from Admission's events anyway if the
 * person ever came back.
 *
 * Only an account-backed subject can have rows here: the table is keyed
 * by `user_id`, and Admission emits `ParticipantAdmitted` for signed-in
 * holders only. An email-only subject gets an empty export and nothing to
 * delete. Both methods are idempotent.
 */
final class HubAccessGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'platform';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        return [
            'hub_access' => HubAccess::withoutBrandScope()
                ->where('user_id', $subject->userId)
                ->orderBy('admitted_at')
                ->get()
                ->map(fn (HubAccess $access): array => [
                    'event_id' => $access->event_id,
                    'admitted_at' => $access->admitted_at->toIso8601String(),
                    'on_site' => $access->on_site,
                ])
                ->all(),
        ];
    }

    /**
     * The rows go, and so does the answer built out of them. `hub-access:
     * {brand}:{user}` holds the event's name, the admission id and the
     * instant the person was admitted for ten minutes — deleting the rows
     * underneath it would keep all three being served to whoever signs in
     * next on that account until the TTL ran out, which is not erasure.
     *
     * The brands are collected before the delete, because afterwards there
     * is nothing left to say which keys to forget. A subject with no rows
     * forgets nothing; both halves stay idempotent.
     */
    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $brandIds = HubAccess::withoutBrandScope()
            ->where('user_id', $subject->userId)
            ->distinct()
            ->pluck('brand_id');

        HubAccess::withoutBrandScope()
            ->where('user_id', $subject->userId)
            ->delete();

        foreach ($brandIds as $brandId) {
            ActiveEventContext::forget((string) $brandId, (string) $subject->userId);
        }
    }
}
