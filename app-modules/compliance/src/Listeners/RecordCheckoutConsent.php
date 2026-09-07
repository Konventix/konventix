<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Illuminate\Support\Carbon;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;

/**
 * Turns a payer's declaration at checkout into Consent rows (GitLab #2).
 *
 * Commerce reports only that someone accepted the purchase terms; which
 * documents that covers is this module's answer, resolved from the same
 * `CheckoutLegalTexts` the checkout page rendered — so the row records the
 * version the payer actually saw, not whatever is current when a queue
 * worker gets around to it.
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
    public function handle(CheckoutConsentGiven $event): void
    {
        // Resolved at call time rather than constructor-injected: the event
        // dispatcher builds a listener with the container it was constructed
        // with, which under Octane is the worker's, not the request's
        // (.ai/rules/listeners.md).
        $texts = app(CheckoutLegalTexts::class)->forBrand($event->brandId);
        $grantConsent = app(GrantConsent::class);
        $acceptedAt = Carbon::parse($event->acceptedAt);

        foreach ($texts as $text) {
            // Escapes BrandScope: an operator-wide text's version carries no
            // brand at all (#76, ADR-0010).
            $version = PolicyVersion::withoutBrandScope()->find($text['versionId']);

            if ($version === null) {
                continue;
            }

            $grantConsent($event->subjectId, $version, $event->captureContext, $acceptedAt);
        }
    }
}
