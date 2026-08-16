<?php

declare(strict_types=1);

namespace Konventix\Commerce\Bookkeeping;

use Carbon\CarbonInterface;
use Konventix\Commerce\Enums\TaxTreatment;

/**
 * One exportable document (ADR-0040): an invoice or a credit note, with its
 * postings already split into tax buckets — one `(revenue account, gross)`
 * pair per rate, summing exactly to the document total. The writers only
 * format; every accounting decision is made before this object exists.
 */
final readonly class JournalEntry
{
    public const string KIND_INVOICE = 'invoice';

    public const string KIND_CREDIT_NOTE = 'credit_note';

    /**
     * @param  list<array{account: string, gross: numeric-string}>  $buckets
     */
    public function __construct(
        public string $kind,
        public string $number,
        public CarbonInterface $issuedOn,
        public string $brandName,
        public string $buyerName,
        public ?string $countryCode,
        public ?string $vatId,
        public TaxTreatment $treatment,
        public string $currency,
        public string $totalNet,
        public string $totalTax,
        public string $totalGross,
        public array $buckets,
    ) {}

    public function isCreditNote(): bool
    {
        return $this->kind === self::KIND_CREDIT_NOTE;
    }
}
