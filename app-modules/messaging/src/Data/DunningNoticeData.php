<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The `messaging.dunning-notice` template's payload (ADR-0041 point 8):
 * what a payment reminder says about the invoice it chases. Commerce
 * declared *that* a level was reached; the wording here is Messaging's.
 */
final class DunningNoticeData extends Data
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $invoiceNumber,
        public readonly int $level,
        public readonly int $daysOverdue,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly string $dueOn,
    ) {}
}
