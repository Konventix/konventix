<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Str;
use Konventix\Commerce\Models\InvoiceNumberRange;
use Konventix\Platform\Models\Brand;

/**
 * Draws the next gapless invoice number from the brand's per-year range
 * (ADR-0006, ADR-0031). Format: PREFIX-YYYY-NNNNN, counter resetting each
 * year — the Phase 2 interview decision, matching the predecessor's
 * bookkeeping-familiar INV-2026-00001 shape with a per-brand prefix.
 *
 * MUST be called inside the issuing transaction: the row lock makes
 * concurrent draws serialize, and a rollback returns the number to the
 * range unused — both properties GoBD's gapless requirement depends on.
 *
 * The prefix defaults from the brand's slug on first draw and lives on the
 * range row from then on, where a later settings UI can edit it (it only
 * ever applies to the *next* year's row — issued numbers never change).
 */
final class DrawInvoiceNumber
{
    public function handle(string $brandId): string
    {
        $year = (int) now()->format('Y');

        $range = InvoiceNumberRange::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($range === null) {
            $range = new InvoiceNumberRange([
                'prefix' => $this->defaultPrefixFor($brandId),
                'year' => $year,
            ]);
            // brand_id from the payload, not ambient context: issuance may
            // run on the operator admin host, which resolves no brand
            // (ADR-0010's escape hatch, same pattern as Admission issuance).
            $range->brand_id = $brandId;
            $range->save();

            // Re-acquire under lock — another transaction may have raced the
            // insert; the unique(brand_id, year) index makes one of them fail
            // and retry naturally.
            $range = InvoiceNumberRange::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $range->counter += 1;
        $range->save();

        return sprintf('%s-%d-%05d', $range->prefix, $range->year, $range->counter);
    }

    private function defaultPrefixFor(string $brandId): string
    {
        $slug = Brand::query()->findOrFail($brandId)->slug;

        return Str::of($slug)->replace('-', '')->upper()->substr(0, 6)->toString();
    }
}
