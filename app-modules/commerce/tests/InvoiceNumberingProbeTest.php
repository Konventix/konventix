<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\InvoiceNumberRange;
use Konventix\Platform\Models\Brand;

/**
 * The concurrency half of GitLab #74: PaidInvoiceTest can only prove
 * idempotency, because Pest runs single-threaded and cannot make two
 * transactions actually race. `commerce:probe-invoice-numbering` can — it
 * spawns real processes — and this test runs it, so the proof is in CI and
 * not only in somebody's terminal.
 *
 * Helper names prefixed `numberingProbe*`: Pest helpers are process-global
 * across every module test file in one run (.ai/rules/tests.md).
 */
beforeEach(function (): void {
    // tests/Pest.php applies RefreshDatabase to every module test, which wraps
    // each test in a transaction. The probe's children connect on their own
    // PDO handles and would see none of the rows this test creates — not even
    // the brand — so the transaction has to go. Rolling it back here makes
    // everything after this line commit for real; RefreshDatabase notices in
    // its own teardown that the connection left its transaction and sets
    // `RefreshDatabaseState::$migrated = false`, so the next test in the
    // process re-migrates and nothing committed here survives into it. That is
    // also why DatabaseTruncation is not used: tests/Pest.php applies
    // RefreshDatabase by path and traits stack rather than replace, so the
    // wrapping transaction would still be there.
    DB::rollBack();
});

it('proves parallel draws produce a gapless, duplicate-free invoice series', function (): void {
    $brand = Brand::factory()->create();

    // Small on purpose: two workers racing five draws each is enough to make
    // the cold-start insert and the row lock collide, and costs CI nothing.
    $exit = Artisan::call('commerce:probe-invoice-numbering', [
        'brand' => $brand->id,
        '--workers' => 2,
        '--draws' => 5,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('distinct and gapless');

    $range = InvoiceNumberRange::withoutBrandScope()->where('brand_id', $brand->id)->sole();

    expect($range->counter)->toBe(10);
});

it('fails loudly when the brand does not exist', function (): void {
    $exit = Artisan::call('commerce:probe-invoice-numbering', [
        'brand' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        '--workers' => 1,
        '--draws' => 1,
    ]);

    expect($exit)->not->toBe(0)
        ->and(Invoice::withoutBrandScope()->count())->toBe(0);
});
