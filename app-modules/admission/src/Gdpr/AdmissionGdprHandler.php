<?php

declare(strict_types=1);

namespace Konventix\Admission\Gdpr;

use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Admission's contribution to GDPR export and deletion.
 *
 * The admissions themselves are kept, scrubbed of nothing: an admission row
 * is a ULID, an event, a role and a validity window — the person is
 * identified by `holder_user_id`, which points at Identity's tombstone and
 * must keep resolving (Round 6 D-BATCH). Deleting them would also erase the
 * attendance record an operator may need for its own obligations (venue
 * capacity, insurance, house-ban enforcement).
 *
 * What is scrubbed is what this module collected *about* the person and
 * holds itself: the invitation address on a claim link (ADR-0032), which is
 * an email Admission stored rather than a reference to one. That is also
 * the one thing this module can answer for a subject with no account at
 * all (ADR-0050 §3): an invitee who never claimed a link has no
 * `holder_user_id` to look up, only the `intended_email` the link was
 * issued to.
 *
 * Both methods are idempotent.
 */
final class AdmissionGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'admission';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            // An invitee who never claimed a link has no account and no
            // admission of their own — the claim link's `intended_email` is
            // the only trace of them this module holds.
            return [
                'claim_links' => AdmissionClaimLink::withoutBrandScope()
                    ->where('intended_email', $subject->email)
                    ->get()
                    ->map(fn (AdmissionClaimLink $link): array => [
                        'admission_id' => $link->admission_id,
                        'intended_email' => $link->intended_email,
                        'claimed_at' => $link->claimed_at?->toIso8601String(),
                    ])
                    ->all(),
            ];
        }

        $subjectId = $subject->userId;

        $admissions = Admission::withoutBrandScope()
            ->where('holder_user_id', $subjectId)
            ->orderBy('created_at')
            ->get();

        return [
            'admissions' => $admissions
                ->map(fn (Admission $admission): array => [
                    'id' => $admission->getKey(),
                    'event_id' => $admission->event_id,
                    'role' => $admission->role,
                    'state' => $admission->state->value,
                    'valid_from' => $admission->valid_from?->toIso8601String(),
                    'valid_until' => $admission->valid_until?->toIso8601String(),
                ])
                ->all(),
            // The tickets this person bought for others (#292 §10): the
            // manager's side of the same rows, listed so the export says
            // what the ticket manager showed them. Ids and roles only —
            // the other people on the booking are not this subject's data.
            'managed_admissions' => Admission::withoutBrandScope()
                ->where('buyer_user_id', $subjectId)
                ->where('holder_user_id', '!=', $subjectId)
                ->orWhere(fn ($query) => $query->where('buyer_user_id', $subjectId)->whereNull('holder_user_id'))
                ->orderBy('created_at')
                ->get()
                ->map(fn (Admission $admission): array => [
                    'id' => $admission->getKey(),
                    'event_id' => $admission->event_id,
                    'role' => $admission->role,
                    'state' => $admission->state->value,
                ])
                ->all(),
            'claim_links' => AdmissionClaimLink::withoutBrandScope()
                ->where(function ($query) use ($subjectId, $admissions): void {
                    $query->where('claimed_by_user_id', $subjectId)
                        ->orWhereIn('admission_id', $admissions->modelKeys());
                })
                ->get()
                ->map(fn (AdmissionClaimLink $link): array => [
                    'admission_id' => $link->admission_id,
                    'intended_email' => $link->intended_email,
                    'claimed_at' => $link->claimed_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            AdmissionClaimLink::withoutBrandScope()
                ->where('intended_email', $subject->email)
                ->update(['intended_email' => null]);

            return;
        }

        $subjectId = $subject->userId;

        $admissionIds = Admission::withoutBrandScope()
            ->where('holder_user_id', $subjectId)
            ->pluck('id');

        AdmissionClaimLink::withoutBrandScope()
            ->where(function ($query) use ($subjectId, $admissionIds): void {
                $query->where('claimed_by_user_id', $subjectId)
                    ->orWhereIn('admission_id', $admissionIds);
            })
            ->update(['intended_email' => null]);

        // A claim this person made stays a fact about the admission, not
        // about them once the account is a tombstone (#292 §10) — the row
        // keeps `claimed_at`, loses who.
        AdmissionClaimLink::withoutBrandScope()
            ->where('claimed_by_user_id', $subjectId)
            ->update(['claimed_by_user_id' => null]);
    }
}
