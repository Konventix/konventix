<?php

declare(strict_types=1);

namespace Konventix\Registration\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
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

    public function exportFor(string $subjectId, string $brandId): array
    {
        $registrations = Registration::withoutBrandScope()
            ->where('brand_id', $brandId)
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
            ->where('brand_id', $brandId)
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

        return [
            'registrations' => $registrations,
            'waitlist_entries' => $waitlist,
        ];
    }

    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
    {
        $scrub = array_fill_keys(self::BILLING_FIELDS, null);

        Registration::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registrant_user_id', $subjectId)
            ->update($scrub);

        WaitlistEntry::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registrant_user_id', $subjectId)
            ->update($scrub);
    }
}
