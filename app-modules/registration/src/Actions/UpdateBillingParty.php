<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Models\Registration;

/**
 * Transfer, pre-invoice half (ADR-0036): the billing party is a plain edit
 * until the first invoice is issued, and frozen from then on — the
 * post-invoice path (credit note + re-issued invoice) lands with
 * Commerce's credit notes in Slice 7 and is a distinct, capability-gated
 * flow, not this Action.
 */
final class UpdateBillingParty
{
    public function handle(Registration $registration, SubmitRegistrationData $data): Registration
    {
        return DB::transaction(function () use ($registration, $data): Registration {
            if ($registration->invoiced_at !== null) {
                throw ValidationException::withMessages([
                    'billing' => __('The billing party is frozen: an invoice has been issued. Changing the recipient requires a credit note and a re-issued invoice.'),
                ]);
            }

            $registration->forceFill([
                'organization_name' => $data->organizationName,
                'billing_recipient_name' => $data->billingRecipientName,
                'billing_email' => $data->billingEmail,
                'billing_address_line' => $data->billingAddressLine,
                'billing_postal_code' => $data->billingPostalCode,
                'billing_city' => $data->billingCity,
                'billing_country_code' => $data->billingCountryCode,
                'billing_vat_id' => $data->billingVatId,
            ])->save();

            return $registration->refresh();
        });
    }
}
