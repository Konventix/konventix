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
 *
 * The type's **price declaration is snapshotted here too** (D-0.8.2-2, #8):
 * a promotion happens days or weeks after the submission, and the
 * registrant pays what they saw when they joined the queue, not what the
 * type costs by then. The whole declaration travels — net amount, tax class
 * and the net-vs-gross display flag — because ADR-0017 makes it one value
 * object and splitting it is the "three loose columns" that ADR rejected;
 * `RequestSettlement` explains why the tax class belongs in the pin and why
 * pinning it freezes no law.
 *
 * Re-joining does **not** re-snapshot: the existing entry is returned
 * untouched, so the queue position and its price are the same promise, made
 * once. A re-join after a price rise must not silently reprice the spot the
 * registrant already holds.
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
                // Null for a `free` type, which has no price at all rather
                // than a zero one (ADR-0017) — `RequestSettlement` never
                // runs for it.
                'price_declaration' => $registrationType->price_declaration,
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
