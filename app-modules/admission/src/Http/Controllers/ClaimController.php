<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Actions\ClaimAdmission;
use Konventix\Admission\Data\ClaimLandingData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Exceptions\ClaimLinkNotClaimableException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Redeems a claim link (ADR-0032).
 *
 * Two guards stack, mirroring Registration's waitlist claim: `signed`
 * validates the URL's signature and its expiry, and `auth` requires an
 * account — D-REG-08 settled that people hold accounts in Phase 1, and a
 * claimed admission has to point at a real identity rather than a name typed
 * into a box.
 *
 * The signature's expiry and the link row's `expires_at` are the same
 * timestamp by construction (`IssueAdmissionClaimLink::urlFor()`), so the two
 * cannot disagree — but the Action re-checks the row anyway, since a link can
 * be revoked or claimed long before its signature lapses.
 *
 * Every failure surfaces as one 410. Distinguishing "already claimed" from
 * "not your address" would tell whoever holds a forwarded link something
 * about the other people on the booking.
 */
final class ClaimController
{
    public function __construct(
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
    ) {}

    /**
     * The landing page (#292 §4.5): what is on offer, before the button
     * that takes it. Same signed URL as `store()` — the GET and the POST
     * share a path, and a signature covers path and query, never the
     * method — so the page posts to its own address. A dead link is the
     * same 410 the POST answers, for the same reason.
     */
    public function show(Request $request, string $link): Response
    {
        /** @var AdmissionClaimLink|null $row */
        $row = AdmissionClaimLink::query()->whereKey($link)->first();

        abort_if($row === null || ! $row->isClaimable(), 410, __('This claim link is no longer usable.'));

        /** @var Admission $admission */
        $admission = Admission::query()->findOrFail($row->admission_id);

        abort_if($admission->holder_user_id !== null, 410, __('This claim link is no longer usable.'));

        $userId = (string) $request->user()->getAuthIdentifier();
        $event = EventSummary::query()->where('event_id', $admission->event_id)->first();

        return Inertia::render('admission/claim/Show', ClaimLandingData::from([
            'linkId' => $row->id,
            'eventName' => $event?->name,
            'eventSlug' => $event?->slug,
            'role' => $admission->role,
            // The inviter as a handle (ADR-0065); the mail that carried the
            // link still names them, this page does not.
            'managerUsername' => $admission->buyer_user_id === null
                ? null
                : Person::query()->find($admission->buyer_user_id)?->username,
            'intendedEmail' => $row->intended_email,
            'expiresAt' => $this->dates->dateTime($row->expires_at, $this->brandTimezone->handle($admission->brand_id)),
            'alreadyHolderOfAnother' => Admission::query()
                ->where('event_id', $admission->event_id)
                ->where('holder_user_id', $userId)
                ->where('state', '!=', AdmissionState::Revoked)
                ->exists(),
            'claimUrl' => $request->fullUrl(),
        ])->toArray());
    }

    public function store(Request $request, string $link, ClaimAdmission $claim): RedirectResponse
    {
        $user = $request->user();

        try {
            $admission = $claim->handle(
                linkId: $link,
                claimantUserId: (string) $user->getKey(),
                claimantEmail: $user->email,
            );
        } catch (ClaimLinkNotClaimableException) {
            abort(410, __('This claim link is no longer usable.'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The ticket is now yours.')]);

        return to_route('admission.tickets.show', ['admission' => $admission->id]);
    }
}
