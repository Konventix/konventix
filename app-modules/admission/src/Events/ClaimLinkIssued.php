<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A claim link was minted for a holder-less admission (#292 §9): bound to
 * an address, or a bearer link to copy. Scalars only (ADR-0004).
 */
final readonly class ClaimLinkIssued
{
    public function __construct(
        public string $linkId,
        public string $admissionId,
        public string $brandId,
        public bool $bound,
    ) {}
}
