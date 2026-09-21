<?php

declare(strict_types=1);

namespace Konventix\Registration\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * Registration's contribution to GDPR export and deletion (SPEC.md "Does
 * not own" — Compliance orchestrates, this module touches its own rows).
 *
 * Deletion is anonymize-with-tombstone in both modes (Round 6 D-BATCH):
 * billing party fields are scrubbed but the rows survive with their ULIDs
 * — an `Admission.registration_id` or a Commerce order's `settles_id` must
 * stay resolvable, and the commercial fact "a registration existed" is
 * itself subject to statutory retention through its invoices. Both methods
 * are idempotent by construction.
 *
 * Entry-condition assertions (ADR-0066 §11) ride with the registration in
 * both directions: exported as the wording the person actually agreed to —
 * the snapshot, never the condition's current text — and anonymized rather
 * than deleted, so a tombstone keeps the registration's history coherent.
 * They are deliberately absent from every export that is not the subject's
 * own: an assertion prices a ticket and is checked at the door, and it is
 * neither a marketing segment nor anything the attendee list (ADR-0064) or a
 * public profile may learn.
 *
 * D-REG-08 keeps `registrant_user_id` non-nullable, so `registrations` and
 * `waitlist_entries` never carry a bare address — a person with no account
 * cannot have submitted one. But `Registrant` (SPEC.md "Consumes" —
 * Identity's events), the display read model keyed by that same user id, is
 * populated from Identity's account events and never cleaned up by them: an
 * account Identity later anonymizes leaves its old address sitting in this
 * projection, reachable only by the address itself once the account no
 * longer resolves to it. That is this module's email branch (ADR-0050 §3).
 */
final class RegistrationGdprHandler implements GdprHandler
{
    private const array BILLING_FIELDS = [
        'organization_name',
        'billing_recipient_name',
        'billing_email',
        'billing_address_line',
        'billing_postal_code',
        'billing_city',
        'billing_country_code',
        'billing_vat_id',
    ];

    public function module(): string
    {
        return 'registration';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [
                'registrants' => Registrant::query()
                    ->where('email', $subject->email)
                    ->get()
                    ->map(fn (Registrant $registrant): array => [
                        'id' => $registrant->id,
                        'display_name' => $registrant->display_name,
                        'email' => $registrant->email,
                    ])
                    ->all(),
            ];
        }

        $subjectId = $subject->userId;

        $registrations = Registration::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Registration $registration): array => [
                'id' => $registration->id,
                'event_id' => $registration->event_id,
                'status' => $registration->status->value,
                'payment_status' => $registration->payment_status->value,
                'submitted_at' => $registration->submitted_at->toIso8601String(),
                ...collect(self::BILLING_FIELDS)
                    ->mapWithKeys(fn (string $field) => [$field => $registration->getAttribute($field)])
                    ->all(),
            ])
            ->all();

        $waitlist = WaitlistEntry::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->orderBy('queued_at')
            ->get()
            ->map(fn (WaitlistEntry $entry): array => [
                'id' => $entry->id,
                'event_id' => $entry->event_id,
                'state' => $entry->state->value,
                'queued_at' => $entry->queued_at->toIso8601String(),
            ])
            ->all();

        // ADR-0066 §11: the wording the person actually agreed to, because
        // that is what they are entitled to see — never the condition's
        // current text, which may have been reworded since. The proof is
        // not here because it was never stored: the platform keeps the
        // declaration, not the document (D-ADM-07).
        $assertions = EntryConditionAssertion::withoutBrandScope()
            ->where('subject_user_id', $subjectId)
            ->with(['entryCondition' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->orderBy('asserted_at')
            ->get()
            ->map(fn (EntryConditionAssertion $assertion): array => [
                'condition_name' => $assertion->entryCondition?->name,
                'asserted_text' => $assertion->assertion_text_snapshot,
                'asserted_at' => $assertion->asserted_at->toIso8601String(),
                'state' => $assertion->state->value,
                'decided_at' => $assertion->decided_at?->toIso8601String(),
            ])
            ->all();

        return [
            'registrations' => $registrations,
            'waitlist_entries' => $waitlist,
            'entry_condition_assertions' => $assertions,
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            Registrant::query()
                ->where('email', $subject->email)
                ->update([
                    'email' => 'anonymized-'.md5((string) $subject->email).'@invalid',
                    'display_name' => 'Deleted account',
                ]);

            return;
        }

        $subjectId = $subject->userId;
        $scrub = array_fill_keys(self::BILLING_FIELDS, null);

        Registration::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->update($scrub);

        WaitlistEntry::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->update($scrub);

        // The assertion is anonymized, never deleted (spec §11): the
        // tombstone keeps the registration's history coherent — a row that
        // vanished would make a verified declaration look as though it had
        // never been made — while the two user references are forgotten.
        // Both updates are idempotent: a second run matches nothing.
        EntryConditionAssertion::withoutBrandScope()
            ->where('subject_user_id', $subjectId)
            ->update(['subject_user_id' => null]);

        EntryConditionAssertion::withoutBrandScope()
            ->where('decided_by_user_id', $subjectId)
            ->update(['decided_by_user_id' => null]);
    }
}
