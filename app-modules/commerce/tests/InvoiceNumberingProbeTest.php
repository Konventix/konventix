<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

afterEach(function (): void {
    // Everything after that rollBack committed for real, so the next test has
    // to start from a fresh schema. RefreshDatabase's own teardown happens to
    // notice a connection that left its transaction and set this itself, but
    // that is an implementation detail of a framework trait — say it outright
    // rather than depend on it.
    RefreshDatabaseState::$migrated = false;
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

    // The range row is what the command would have written — it draws
    // numbers, it never creates an Invoice, so counting invoices here would
    // have been true whatever the command did.
    expect($exit)->not->toBe(0)
        ->and(InvoiceNumberRange::withoutBrandScope()->count())->toBe(0);
});

it('reports a worker that hangs instead of throwing out of the run', function (): void {
    $brand = Brand::factory()->create();

    // A timeout below a child's own boot time stands in for the deadlock this
    // probe exists to catch: the point is that the run ends in the report, not
    // in a ProcessTimedOutException.
    $exit = Artisan::call('commerce:probe-invoice-numbering', [
        'brand' => $brand->id,
        '--workers' => 2,
        '--draws' => 5,
        '--timeout' => 0.05,
    ]);

    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('Worker 0 timed out')
        ->and($output)->toContain('Worker 1 timed out')
        // The five checks are still printed: a hung worker is reported like
        // any other failure, not instead of the report.
        ->and($output)->toContain('worker(s) exited 0');
});
