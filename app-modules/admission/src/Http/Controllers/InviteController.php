<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Actions\RevokeClaimLink;
use Konventix\Admission\Actions\SendClaimLink;
use Konventix\Admission\Data\InviteData;
use Konventix\Admission\Http\Concerns\FindsManagedAdmission;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use RuntimeException;

/**
 * Inviting somebody to hold one of the manager's admissions (#292 §4.3,
 * #16 point 1): a bound link is mailed to its address, an unbound one is
 * minted for the manager to copy — the ticket manager rebuilds its URL on
 * every render (`IssueAdmissionClaimLink::urlFor()`), so nothing has to
 * travel back through the session. `destroy()` withdraws whatever live
 * link the admission has.
 */
final class InviteController
{
    use FindsManagedAdmission;

    public function store(
        Request $request,
        Admission $admission,
        IssueAdmissionClaimLink $issue,
        SendClaimLink $send,
    ): RedirectResponse {
        $admission = $this->managedAdmission($request, $admission);
        $data = InviteData::validateAndCreate($request);

        try {
            $link = $issue->handle($admission, $data->email);
        } catch (RuntimeException) {
            // `IssueAdmissionClaimLink` refuses a held or revoked admission
            // with a plain RuntimeException; to the manager that is a form
            // error, not a crash.
            throw ValidationException::withMessages(['email' => __('This ticket cannot be offered right now.')]);
        }

        $sent = $send->handle($link, $admission, (string) $request->user()->getAuthIdentifier());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $sent
                ? __('Invitation sent to :email.', ['email' => (string) $data->email])
                : ($data->email === null
                    ? __('Invite link ready to copy.')
                    : __('The link was created but the mail could not be sent — copy it instead.')),
        ]);

        return back();
    }

    public function destroy(Request $request, Admission $admission, RevokeClaimLink $revoke): RedirectResponse
    {
        $admission = $this->managedAdmission($request, $admission);

        $link = AdmissionClaimLink::query()
            ->where('admission_id', $admission->id)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->first();

        if ($link !== null) {
            $revoke->handle($link);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The invitation was withdrawn.')]);

        return back();
    }
}
