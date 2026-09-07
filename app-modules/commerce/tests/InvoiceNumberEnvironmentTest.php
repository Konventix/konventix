<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Actions\DrawCreditNoteNumber;
use Konventix\Commerce\Actions\DrawInvoiceNumber;
use Konventix\Platform\Models\Brand;

/**
 * A number drawn on an instance that is not production says so in the number
 * itself (GitLab #74, ADR-0053): `STAGING-SXLAN-2026-00042` can never be
 * mistaken for, or collide with, the real `SXLAN-2026-00042`. The counter is
 * untouched — only its rendering differs — so the range row stays the single
 * gapless series it is under ADR-0031.
 *
 * Helper names prefixed `numberEnv*`: Pest helpers are process-global across
 * every module test file in one run (.ai/rules/tests.md).
 */
function numberEnvBrand(): Brand
{
    return Brand::factory()->create(['slug' => 'sxlan']);
}

it('draws an unmarked invoice number on production', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    config(['app.env' => 'production']);

    expect(app(DrawInvoiceNumber::class)->handle($brand->id))
        ->toBe(sprintf('SXLAN-%d-00001', now()->year));
});

it('marks an invoice number drawn on a non-production instance', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    config(['app.env' => 'staging']);

    expect(app(DrawInvoiceNumber::class)->handle($brand->id))
        ->toBe(sprintf('STAGING-SXLAN-%d-00001', now()->year));
});

it('marks an invoice number with the environment name, whatever it is', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    config(['app.env' => 'local']);

    expect(app(DrawInvoiceNumber::class)->handle($brand->id))
        ->toBe(sprintf('LOCAL-SXLAN-%d-00001', now()->year));

    // ADR-0053: a missing or misspelt APP_ENV is a non-production instance,
    // and the marker is one segment even when the name is not (`review-42`).
    config(['app.env' => 'review-42']);

    expect(app(DrawInvoiceNumber::class)->handle($brand->id))
        ->toBe(sprintf('REVIEW42-SXLAN-%d-00002', now()->year));
});

it('draws an unmarked invoice number under the test environment', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);

    // The suite runs as `testing` and asserts the production shape in a dozen
    // places (SettlementChainTest, SeedDemoDataCommandTest); the exemption is
    // what keeps the marker from rewriting them. Asserted, not assumed.
    expect(config('app.env'))->toBe('testing')
        ->and(app(DrawInvoiceNumber::class)->handle($brand->id))
        ->toBe(sprintf('SXLAN-%d-00001', now()->year));
});

it('draws an unmarked credit-note number on production', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    config(['app.env' => 'production']);

    expect(app(DrawCreditNoteNumber::class)->handle($brand->id))
        ->toBe(sprintf('GS-SXLAN-%d-00001', now()->year));
});

it('marks a credit-note number drawn on a non-production instance', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    config(['app.env' => 'staging']);

    expect(app(DrawCreditNoteNumber::class)->handle($brand->id))
        ->toBe(sprintf('STAGING-GS-SXLAN-%d-00001', now()->year));
});

it('leaves the counter untouched by the marker', function (): void {
    $brand = numberEnvBrand();
    $this->withBrand($brand);
    $prefix = Str::of($brand->slug)->replace('-', '')->upper()->substr(0, 6)->toString();

    config(['app.env' => 'production']);
    $first = app(DrawInvoiceNumber::class)->handle($brand->id);

    config(['app.env' => 'staging']);
    $second = app(DrawInvoiceNumber::class)->handle($brand->id);

    // One series, two renderings: the marker is presentation, the counter is
    // the gapless fact (ADR-0031).
    expect($first)->toBe(sprintf('%s-%d-00001', $prefix, now()->year))
        ->and($second)->toBe(sprintf('STAGING-%s-%d-00002', $prefix, now()->year));
});
