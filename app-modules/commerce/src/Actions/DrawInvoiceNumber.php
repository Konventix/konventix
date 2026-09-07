<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Str;
use Konventix\Commerce\Models\InvoiceNumberRange;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Models\Brand;

/**
 * Draws the next gapless invoice number from the brand's per-year range
 * (ADR-0006, ADR-0031). Format: PREFIX-YYYY-NNNNN, counter resetting each
 * year — the Phase 2 interview decision, matching the predecessor's
 * bookkeeping-familiar INV-2026-00001 shape with a per-brand prefix.
 *
 * Off production the number carries the environment as a leading segment
 * (STAGING-SXLAN-2026-00042, GitLab #74, ADR-0053) — see environmentMarker().
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
    public function __construct(private readonly DeploymentEnvironment $environment) {}

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
            // insert; the unique(brand_id, year) index makes one of them fail.
            // Nothing here retries: the loser's whole transaction rolls back,
            // so it burns no number and the series stays gapless, but the
            // caller has to draw again. `commerce:probe-invoice-numbering`
            // does exactly that in its worker loop.
            $range = InvoiceNumberRange::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $range->counter += 1;
        $range->save();

        return sprintf('%s%s-%d-%05d', $this->environmentMarker(), $range->prefix, $range->year, $range->counter);
    }

    /**
     * The environment marker a drawn number carries off production (GitLab
     * #74, ADR-0053): `STAGING-SXLAN-2026-00042` cannot be mistaken for, or
     * collide with, the real `SXLAN-2026-00042`, so a rehearsal on staging
     * can never leak a number that looks like a booked one. Empty on
     * production, where the format is unchanged.
     *
     * The counter is untouched — the range row and its lock stay the one
     * gapless series (ADR-0031); only the rendering differs, and the marker
     * becomes part of the stored, immutable number.
     *
     * `testing` is exempt on purpose: the whole suite runs as `testing` and
     * asserts the production shape in a dozen places (SettlementChainTest,
     * SeedDemoDataCommandTest, the bookkeeping export fixtures). Marking
     * there would rewrite those assertions without proving anything the
     * `staging` case does not — InvoiceNumberEnvironmentTest covers the
     * exemption itself.
     *
     * Non-alphanumerics are stripped so the marker is always exactly one
     * dash-separated segment: a `review-42` instance must not render a
     * number that parses as two.
     */
    private function environmentMarker(): string
    {
        if ($this->environment->isProduction() || $this->environment->name() === 'testing') {
            return '';
        }

        return Str::of($this->environment->name())->replaceMatches('/[^A-Za-z0-9]/', '')->upper()->toString().'-';
    }

    private function defaultPrefixFor(string $brandId): string
    {
        $slug = Brand::query()->findOrFail($brandId)->slug;

        return Str::of($slug)->replace('-', '')->upper()->substr(0, 6)->toString();
    }
}
