<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Str;
use Konventix\Commerce\Models\CreditNoteNumberRange;
use Konventix\Platform\Models\Brand;

/**
 * Draws the next gapless credit-note number from the brand's own per-year
 * range — a series separate from invoices, so the two counters cannot perturb
 * each other and a credit note is identifiable from its number alone.
 *
 * Format `GS-PREFIX-YYYY-NNNNN` (*Gutschrift*), deliberately echoing
 * `DrawInvoiceNumber`'s `PREFIX-YYYY-NNNNN` so both read as the same family
 * of document to a bookkeeper.
 *
 * MUST be called inside the issuing transaction, for the same reason invoice
 * numbers are: the row lock serializes concurrent draws, and a rollback
 * returns the number unused. Both are what GoBD's gapless requirement rests
 * on.
 */
final class DrawCreditNoteNumber
{
    public const string SERIES_PREFIX = 'GS';

    public function handle(string $brandId): string
    {
        $year = (int) now()->format('Y');

        $range = CreditNoteNumberRange::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($range === null) {
            $range = new CreditNoteNumberRange([
                'prefix' => $this->defaultPrefixFor($brandId),
                'year' => $year,
            ]);
            // brand_id explicitly: issuance runs on the operator admin host,
            // which resolves no brand (ADR-0010's escape hatch).
            $range->brand_id = $brandId;
            $range->save();

            // Re-acquire under lock — another transaction may have raced the
            // insert; unique(brand_id, year) makes one of them fail and retry.
            $range = CreditNoteNumberRange::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $range->counter += 1;
        $range->save();

        return sprintf('%s-%s-%d-%05d', self::SERIES_PREFIX, $range->prefix, $range->year, $range->counter);
    }

    private function defaultPrefixFor(string $brandId): string
    {
        $slug = Brand::query()->findOrFail($brandId)->slug;

        return Str::of($slug)->replace('-', '')->upper()->substr(0, 6)->toString();
    }
}
