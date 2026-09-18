<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * The invitation mail for a bound claim link was handed to Messaging — or
 * was not (#292 §9): `sent` is false when `SendMessage` refused, which the
 * manager sees as "copy it instead" and an operator sees on the dashboard.
 */
final readonly class ClaimLinkSent
{
    public function __construct(
        public string $linkId,
        public string $admissionId,
        public string $brandId,
        public bool $sent,
    ) {}
}
