<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Konventix\Admission\Actions\ClaimAdmission;
use Konventix\Admission\Exceptions\ClaimLinkNotClaimableException;

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
    public function store(Request $request, string $link, ClaimAdmission $claim): RedirectResponse
    {
        $user = $request->user();

        try {
            $claim->handle(
                linkId: $link,
                claimantUserId: (string) $user->getKey(),
                claimantEmail: $user->email,
            );
        } catch (ClaimLinkNotClaimableException) {
            abort(410, __('This claim link is no longer usable.'));
        }

        return back()->with('status', __('Your admission has been assigned to you.'));
    }
}
