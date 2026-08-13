<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Documents\BadgeDocumentRenderer;
use Konventix\Admission\Events\BadgeIssued;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\BadgeIssuance;
use Konventix\Admission\Models\BadgeTemplate;
use Throwable;

/**
 * Prints a badge: resolves the template, renders the PDF, records the
 * issuance and emits `BadgeIssued` (D-ADM-05).
 *
 * Returns the PDF bytes and stores nothing. A badge is reproducible from the
 * admission plus its template, so unlike a GoBD-archived invoice (ADR-0031)
 * there is nothing to gain from keeping attendee-bearing documents on disk —
 * and a retention rule to answer for if we did.
 *
 * The QR reuses the admission's existing token when one is supplied, and only
 * mints a fresh one when there is no signing key problem and none was given.
 * Minting on every print would strand the attendee's previous QR each time a
 * badge is reprinted, because a new nonce supersedes the old token.
 */
final class IssueBadge
{
    public function __construct(
        private readonly BadgeDocumentRenderer $renderer,
        private readonly IssueAdmissionToken $issueToken,
    ) {}

    public function handle(
        Admission $admission,
        ?string $issuedByUserId = null,
        ?string $holderName = null,
        ?string $token = null,
    ): string {
        $template = BadgeTemplate::forAdmission($admission);

        $bytes = $this->renderer->render(
            admission: $admission,
            template: $template,
            token: $token ?? $this->tokenFor($admission),
            holderName: $holderName,
        );

        $issuance = DB::transaction(function () use ($admission, $template, $issuedByUserId): BadgeIssuance {
            // Reprint is a fact read from the ledger, not a caller's claim.
            $reprint = BadgeIssuance::withoutBrandScope()
                ->where('brand_id', $admission->brand_id)
                ->where('admission_id', $admission->id)
                ->exists();

            $issuance = new BadgeIssuance([
                'admission_id' => $admission->id,
                'badge_template_id' => $template?->id,
                'issued_by_user_id' => $issuedByUserId,
                'reprint' => $reprint,
                'issued_at' => now(),
            ]);
            // brand_id from the admission, not ambient context (ADR-0010).
            $issuance->forceFill(['brand_id' => $admission->brand_id])->save();

            return $issuance;
        });

        event(new BadgeIssued(
            issuanceId: $issuance->id,
            admissionId: $admission->id,
            eventId: $admission->event_id,
            brandId: $admission->brand_id,
            badgeTemplateId: $template?->id,
            issuedByUserId: $issuedByUserId,
            reprint: $issuance->reprint,
            issuedAt: $issuance->issued_at->toIso8601String(),
        ));

        return $bytes;
    }

    /**
     * A brand with no signing key yet is a misconfiguration that should not
     * stop a badge printing — the QR is one element of the document, and a
     * badge with a name and a role is still usable at a manual desk. The
     * layout already degrades to no QR when this returns null.
     */
    private function tokenFor(Admission $admission): ?string
    {
        try {
            return $this->issueToken->handle($admission);
        } catch (Throwable) {
            return null;
        }
    }
}
