<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Fortify\Features;
use Laravel\Pennant\Feature;

abstract class TestCase extends BaseTestCase
{
    /**
     * The brand every test runs under, created lazily on first use.
     */
    private ?Brand $testBrand = null;

    /**
     * ResolveBrand fronts the whole `web` group (ADR-0001), so any test that
     * makes an HTTP request needs a brand answering on the test host. Created
     * eagerly for database-backed tests; it doubles as the default brand
     * withBrand() falls back to, so middleware and explicit context agree.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // GenerateInvoiceDocument/GenerateCreditNoteDocument dispatch
        // afterCommit() from inside a DB::transaction() that is itself
        // nested in RefreshDatabase's wrapping transaction — that nested
        // commit never reaches transaction level 0, so the dispatch runs
        // synchronously, in-process, right there, on every test regardless
        // of whether it faked the queue. Without this, that document job
        // writes a real PDF onto the host's `storage/app/invoices` on any
        // test that reaches it — this fakes the disk globally so no test
        // can do that by omission; a test asserting the archive re-fakes
        // (or asserts against) the same disk explicitly.
        Storage::fake((string) config('filesystems.invoices_disk', 'invoices'));

        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->testBrand = Brand::factory()->withDomain('localhost')->create();
        }
    }

    /**
     * ADR-0010: tests must always establish a brand context, so a test can
     * never accidentally pass because nothing was scoped. Call this from any
     * test that touches brand-owned models.
     *
     * Deliberately not automatic in setUp(): the database is not migrated for
     * every test, and creating a brand unconditionally would force it.
     */
    protected function withBrand(?Brand $brand = null): Brand
    {
        $brand ??= $this->testBrand ??= Brand::factory()->create();

        app(BrandContext::class)->set($brand);

        return $brand;
    }

    /**
     * Turn a Pennant-gated module on for the current brand. Modules default to
     * off (ADR-0003), so a test exercising one must opt in explicitly.
     */
    protected function activateModule(string $module, ?Brand $brand = null): void
    {
        $brand ??= $this->withBrand();

        Feature::for($brand)
            ->activate(app(ModuleGate::class)->featureName($module));
    }

    /**
     * Turn a per-brand feature on (ADR-0047) — the module-less counterpart
     * to activateModule(), for features like `commerce:shop` that have no
     * module gate behind them.
     */
    protected function activateFeature(string $feature, ?Brand $brand = null): void
    {
        Feature::for($brand ?? $this->withBrand())->activate($feature);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            app(BrandContext::class)->forget();
        }

        $this->testBrand = null;

        parent::tearDown();
    }
}
