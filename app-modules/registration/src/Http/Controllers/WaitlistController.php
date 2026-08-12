<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * Claims a waitlist offer (D-REG-12): the signed link from the offer mail
 * lands here. The route carries `signed` middleware (expiry = the offer's
 * own), plus `auth` — and the claimant must BE the queued registrant, a
 * link is not transferable.
 *
 * Conversion recreates the submission from the entry's snapshot through
 * `SubmitRegistration` with the quota-consumption skip (the units are
 * already held by the offer).
 */
final class WaitlistController
{
    public function claim(Request $request, string $entry, SubmitRegistration $submitRegistration): RedirectResponse
    {
        /** @var WaitlistEntry $model */
        $model = WaitlistEntry::query()->findOrFail($entry);

        abort_unless($model->registrant_user_id === (string) $request->user()?->getKey(), 403);
        abort_unless($model->state === WaitlistState::Offered, 410, 'This offer is no longer open.');
        abort_if($model->offer_expires_at?->isPast() ?? true, 410, 'This offer has expired.');

        $type = $model->registrationType()->firstOrFail();

        $submitRegistration->handle(
            $type,
            $model->registrant_user_id,
            new SubmitRegistrationData(
                organizationName: $model->organization_name,
                billingRecipientName: $model->billing_recipient_name,
                billingEmail: $model->billing_email,
                billingAddressLine: $model->billing_address_line,
                billingPostalCode: $model->billing_postal_code,
                billingCity: $model->billing_city,
                billingCountryCode: $model->billing_country_code,
                billingVatId: $model->billing_vat_id,
            ),
            fromWaitlist: $model,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your spot is confirmed — registration submitted.')]);

        return to_route('registration.show', ['event_ulid' => $model->event_id, 'type' => $model->registration_type_id]);
    }
}
