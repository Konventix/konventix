<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Str;
use Konventix\Commerce\Models\CreditNoteNumberRange;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Models\Brand;

/**
 * Draws the next gapless credit-note number from the brand's own per-year
 * range — a series separate from invoices, so the two counters cannot perturb
 * each other and a credit note is identifiable from its number alone.
 *
 * Format `GS-PREFIX-YYYY-NNNNN` (*Gutschrift*), deliberately echoing
 * `DrawInvoiceNumber`'s `PREFIX-YYYY-NNNNN` so both read as the same family
 * of document to a bookkeeper. Off production both carry the environment as
 * a leading segment (STAGING-GS-SXLAN-2026-00042, GitLab #74, ADR-0053) —
 * see environmentMarker().
 *
 * MUST be called inside the issuing transaction, for the same reason invoice
 * numbers are: the row lock serializes concurrent draws, and a rollback
 * returns the number unused. Both are what GoBD's gapless requirement rests
 * on.
 */
final class DrawCreditNoteNumber
{
    public const string SERIES_PREFIX = 'GS';

    public function __construct(private readonly DeploymentEnvironment $environment) {}

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
            // insert; unique(brand_id, year) makes one of them fail. Nothing
            // here retries: the loser's transaction rolls back whole, so it
            // burns no number, but the caller has to draw again — same as
            // DrawInvoiceNumber.
            $range = CreditNoteNumberRange::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $range->counter += 1;
        $range->save();

        return sprintf('%s%s-%s-%d-%05d', $this->environmentMarker(), self::SERIES_PREFIX, $range->prefix, $range->year, $range->counter);
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
