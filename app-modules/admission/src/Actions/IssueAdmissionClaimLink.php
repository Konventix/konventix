<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\ClaimLinkIssued;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use RuntimeException;

/**
 * Mints a claim link for a holder-less admission (ADR-0032).
 *
 * The signed URL's expiry is the link row's own `expires_at`, exactly as
 * Registration's waitlist offer does it — passing the domain object's
 * timestamp to `temporarySignedRoute` means the signature and the business
 * rule cannot drift apart and disagree about when the offer closed.
 *
 * Re-issuing supersedes: the previous live link is revoked in the same
 * transaction. Otherwise a partial unique index would simply reject the
 * second call, and an organizer re-sending a lost link would be stuck. It
 * also means a forwarded old link stops working the moment a new one is sent,
 * which is the behaviour someone re-sending a link expects.
 */
final class IssueAdmissionClaimLink
{
    public const int LIFETIME_HOURS = 168;

    public function handle(Admission $admission, ?string $intendedEmail = null): AdmissionClaimLink
    {
        return DB::transaction(function () use ($admission, $intendedEmail): AdmissionClaimLink {
            $this->guardClaimable($admission);

            AdmissionClaimLink::withoutBrandScope()
                ->where('brand_id', $admission->brand_id)
                ->where('admission_id', $admission->id)
                ->whereNull('claimed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $link = new AdmissionClaimLink([
                'admission_id' => $admission->id,
                'intended_email' => $intendedEmail,
                'expires_at' => now()->addHours(self::LIFETIME_HOURS),
            ]);
            // brand_id from the admission, not ambient context (ADR-0010).
            $link->forceFill(['brand_id' => $admission->brand_id])->save();

            event(new ClaimLinkIssued(
                linkId: $link->id,
                admissionId: $admission->id,
                brandId: $admission->brand_id,
                bound: $intendedEmail !== null,
            ));

            return $link;
        });
    }

    /**
     * The signed URL a recipient follows. Built here rather than in a
     * controller so the expiry can only ever come from the row.
     */
    public function urlFor(AdmissionClaimLink $link): string
    {
        return URL::temporarySignedRoute(
            'admission.claim',
            $link->expires_at,
            ['link' => $link->id],
        );
    }

    private function guardClaimable(Admission $admission): void
    {
        if ($admission->holder_user_id !== null) {
            throw new RuntimeException(sprintf(
                'Admission [%s] already has a holder; claiming is for holder-less placeholders (ADR-0032). '
                .'Reassignment is a different, capability-gated act.',
                $admission->id,
            ));
        }

        if (! in_array($admission->state, [AdmissionState::Issued, AdmissionState::Assigned], true)) {
            throw new RuntimeException(sprintf(
                'Admission [%s] is %s and cannot be claimed.',
                $admission->id,
                $admission->state->value,
            ));
        }
    }
}
