<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\WaitlistEntryQueued;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * Puts a submission that hit an exhausted quota onto the type's waitlist
 * (D-REG-12): the whole submission queues (all-or-nothing matched
 * `SubmitRegistration::consumeQuotas()`), with the billing party
 * snapshotted for a verbatim later conversion. One live queue position
 * per registrant and type — re-joining while queued or offered is a
 * no-op returning the existing entry.
 */
final class JoinWaitlist
{
    public function handle(
        RegistrationType $registrationType,
        string $registrantUserId,
        SubmitRegistrationData $data,
    ): WaitlistEntry {
        return DB::transaction(function () use ($registrationType, $registrantUserId, $data): WaitlistEntry {
            $existing = WaitlistEntry::query()
                ->where('registration_type_id', $registrationType->id)
                ->where('registrant_user_id', $registrantUserId)
                ->whereIn('state', [WaitlistState::Queued, WaitlistState::Offered])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $entry = WaitlistEntry::create([
                'registration_type_id' => $registrationType->id,
                'event_id' => $registrationType->event_id,
                'registrant_user_id' => $registrantUserId,
                'state' => WaitlistState::Queued,
                'organization_name' => $data->organizationName,
                'billing_recipient_name' => $data->billingRecipientName,
                'billing_email' => $data->billingEmail,
                'billing_address_line' => $data->billingAddressLine,
                'billing_postal_code' => $data->billingPostalCode,
                'billing_city' => $data->billingCity,
                'billing_country_code' => $data->billingCountryCode,
                'billing_vat_id' => $data->billingVatId,
                'queued_at' => now(),
            ]);

            event(new WaitlistEntryQueued(
                waitlistEntryId: $entry->id,
                registrationTypeId: $registrationType->id,
                eventId: $registrationType->event_id,
                brandId: $entry->brand_id,
                registrantUserId: $registrantUserId,
                queuedAt: $entry->queued_at->toIso8601String(),
            ));

            return $entry;
        });
    }
}
