<?php

declare(strict_types=1);

namespace Konventix\Admission\Gdpr;

use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EventPresence;
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
 * The presence row (D-HUB-01) is the other thing this module holds about
 * the person rather than about the admission: when they first got in,
 * where they last scanned, whether the live hub is still open to them. It
 * is exported, and deleted in both modes — a presence without a person
 * means nothing, and unlike the admission it records no obligation of
 * the operator's.
 *
 * The console check-ins an admin wrote (#329, attendee-list design §6) are
 * the third thing: `check_ins.actor_user_id` says who let a guest in from
 * the console. Exported with the admin's data, and nulled on deletion in both
 * modes — but the rows stay, because they are the *guest's* attendance
 * record, not the admin's.
 *
 * Those rows are the guest's data as well, and since #329 they hold more than
 * a time: `check_ins.conditions_checked` records what the door or the console
 * decided about the holder's entry conditions (`student: refused`,
 * ADR-0066). So the holder's export lists every check-in on their own
 * admissions, with the outcomes (#329 final review M3). The rows themselves
 * are kept on erasure like the admission they belong to — the attendance
 * record — and then point at Identity's tombstone only; Registration's
 * `EntryConditionAssertion` holds the same outcome and anonymises it there.
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
            'event_presence' => EventPresence::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('first_admitted_at')
                ->get()
                ->map(fn (EventPresence $presence): array => [
                    'event_id' => $presence->event_id,
                    'first_admitted_at' => $presence->first_admitted_at->toIso8601String(),
                    'last_check_in_at' => $presence->last_check_in_at->toIso8601String(),
                    'last_direction' => $presence->last_direction,
                    'hub_access' => $presence->hub_access,
                ])
                ->all(),
            // Every door or console check-in on this person's own tickets,
            // accepted or refused, with the entry-condition outcomes an
            // accepted one carries (#329 final review M3). The admin who
            // wrote a console row is another person, so no actor here.
            'check_ins' => CheckIn::withoutBrandScope()
                ->whereIn('admission_id', $admissions->modelKeys())
                ->orderBy('scanned_at')
                ->orderBy('id')
                ->get()
                ->map(fn (CheckIn $checkIn): array => [
                    'id' => $checkIn->getKey(),
                    'admission_id' => $checkIn->admission_id,
                    'event_day_id' => $checkIn->event_day_id,
                    'direction' => $checkIn->direction,
                    'result' => $checkIn->result->value,
                    'reason_code' => $checkIn->reason_code,
                    'scanned_at' => $checkIn->scanned_at->toIso8601String(),
                    'conditions_checked' => $checkIn->conditions_checked,
                ])
                ->all(),
            // Check-ins this person wrote as an operator (#329 §6): ids and
            // facts only — the guest on the other end is not this subject.
            'console_check_ins' => CheckIn::withoutBrandScope()
                ->where('actor_user_id', $subjectId)
                ->orderBy('scanned_at')
                ->get()
                ->map(fn (CheckIn $checkIn): array => [
                    'id' => $checkIn->getKey(),
                    'admission_id' => $checkIn->admission_id,
                    'direction' => $checkIn->direction,
                    'result' => $checkIn->result->value,
                    'scanned_at' => $checkIn->scanned_at->toIso8601String(),
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

        // Both modes (D-HUB-01): the row says where *this person* stood at
        // the event, and there is nothing left to say once they are a
        // tombstone. Platform's own hub_access read model is its concern.
        EventPresence::withoutBrandScope()
            ->where('user_id', $subjectId)
            ->delete();

        // Both modes (#329 §6): the check-in stays — it is the guest's
        // attendance — and forgets which admin wrote it.
        CheckIn::withoutBrandScope()
            ->where('actor_user_id', $subjectId)
            ->update(['actor_user_id' => null]);
    }
}
