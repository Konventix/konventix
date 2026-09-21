<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Compliance\Listeners\Concerns\RecordsResolvedConsents;

/**
 * Turns a payer's declaration at checkout into Consent rows (GitLab #2,
 * ADR-0066 §6.3).
 *
 * Commerce reports only that someone accepted the purchase terms and under
 * which circumstances — the opaque binding bag its order lines carried, plus
 * the provider the payer chose in step one and the order itself. Which
 * documents that covers is this module's answer.
 *
 * Since ADR-0066 the resolution runs on **that** context and no longer on
 * the brand alone (D-CPL-07 point 4): the confirmation step listed the texts
 * that apply to this purchase through this provider, and the rows have to be
 * for exactly those. Re-deriving them from the brand is what would let the
 * page and the record drift — a payer held to a text they were never shown,
 * or a text they were shown going unrecorded.
 *
 * Nothing is recorded when a document has no published, effective version:
 * a Consent row pointing at a text nobody published would look like evidence
 * and be none. The checkout stays usable in that state (Commerce only
 * demands the declaration when there is something to declare), which is what
 * keeps a fresh instance from being unable to sell anything at all.
 *
 * `GrantConsent` is idempotent per (subject, live version), so a
 * double-submitted checkout or a retried payment start is one agreement.
 */
final class RecordCheckoutConsent
{
    use RecordsResolvedConsents;

    public function handle(CheckoutConsentGiven $event): void
    {
        $this->recordResolvedConsents(
            $event->brandId,
            $event->subjectId,
            $event->captureContext,
            $event->acceptedAt,
            $event->bindingContext,
        );
    }
}
