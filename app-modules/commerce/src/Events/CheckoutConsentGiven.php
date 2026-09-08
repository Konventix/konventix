<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * A payer confirmed the purchase terms before placing an order (GitLab #2):
 * the cart checkout and the registration checkout both emit this the moment
 * `accept_terms` is accepted, before the order is placed.
 *
 * Commerce says only *that* a declaration was made, and by whom. Which legal
 * documents that covers, and which version of each was in force, is
 * Compliance's answer — it resolves the same texts the page put in front of
 * the payer and writes one Consent row per document. Commerce may not write
 * that row itself: modules talk through events only, and Compliance's models
 * and Actions are not reachable from here (ADR-0004, enforced by
 * tests/Unit/ArchitectureTest.php).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CheckoutConsentGiven
{
    public function __construct(
        public string $brandId,
        public string $subjectId,
        /** Where the declaration was captured — `checkout` for both paths. */
        public string $captureContext,
        public string $acceptedAt,
    ) {}
}
