<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Reads back the aftermath of a real k6 rush against the seeded "Rush"
 * brand (ADR-0047 point 6): `tests/Load/ticket-rush.js` hits the live dev
 * stack — `docker compose --profile load run --rm k6 run
 * /scripts/ticket-rush.js` — which writes to the real `konventix` database,
 * never the `testing` one phpunit.xml points every other test at. So this
 * file talks to a database connection of its own (`rushDb()` below) rather
 * than anything RefreshDatabase would give it, and carries no
 * `RefreshDatabase` at all — there is nothing here for that trait to do,
 * and refreshing the wrong database would be actively wrong.
 *
 * `->group('load')` (phpunit.xml excludes it from the default suite run) —
 * this test has no place in an ordinary `php artisan test`, which never
 * boots a server for k6 to have hit. Run it explicitly:
 * `php artisan test --group=load tests/Load/RushResultTest.php`.
 */
uses(TestCase::class);

const RUSH_SUMMARY_PATH = __DIR__.'/results/summary.json';

// Copied from database/seeders/Demo/DemoRushSeeder.php — keep in sync.
const RUSH_TYPE_ID = '01M1206XWZPSXJPXXBNJ9ZYJC1';

/**
 * A connection pointed at the real dev database, independent of whatever
 * `DB_DATABASE` phpunit.xml's `<php>` block already forced onto every other
 * connection this process resolves. Built off the `pgsql` connection's own
 * config (host, credentials) rather than hand-typed a second time.
 */
function rushDb(): ConnectionInterface
{
    config(['database.connections.pgsql_rush' => array_merge(
        (array) config('database.connections.pgsql'),
        ['database' => 'konventix'],
    )]);

    return DB::connection('pgsql_rush');
}

it('reports zero server errors and no oversold capacity from the last k6 rush', function () {
    if (! file_exists(RUSH_SUMMARY_PATH)) {
        $this->markTestSkipped('No '.RUSH_SUMMARY_PATH.' — run the k6 rush first: '
            .'docker compose --profile load run --rm k6 run /scripts/ticket-rush.js');
    }

    $summary = json_decode((string) file_get_contents(RUSH_SUMMARY_PATH), true, flags: JSON_THROW_ON_ERROR);
    $metrics = $summary['metrics'] ?? [];

    $serverErrors = (int) ($metrics['server_errors']['values']['count'] ?? -1);
    $failedRate = (float) ($metrics['http_req_failed']['values']['rate'] ?? 1.0);
    $admitted = (int) ($metrics['admitted']['values']['count'] ?? 0);

    expect($serverErrors)->toBe(0);

    if (($summary['profile'] ?? 'reduced') === 'full') {
        expect($failedRate)->toBeLessThan(0.01);
    } else {
        $this->addToAssertionCount(1);
        fwrite(STDOUT, sprintf("Reduced profile: http_req_failed %.2f%% (the 1%% bar applies to the full 1,000-VU profile only).\n", $failedRate * 100));
    }

    $db = rushDb();

    $brandId = $db->table('brands')->where('slug', 'rush')->value('id');
    expect($brandId)->not->toBeNull();

    // The quota is the one thing a rush must never violate: 300 capacity,
    // 300 consumed, never more — the overflow queues instead (D-REG-12).
    $consumed = (int) $db->table('quotas')
        ->join('registration_type_quota', 'registration_type_quota.quota_id', '=', 'quotas.id')
        ->where('registration_type_quota.registration_type_id', RUSH_TYPE_ID)
        ->value('quotas.consumed');
    expect($consumed)->toBe(300);

    $stockConsumed = (int) $db->table('product_variants')
        ->join('products', 'products.id', '=', 'product_variants.product_id')
        ->where('products.slug', 'rush-shirt')
        ->value('product_variants.stock_consumed');
    expect($stockConsumed)->toBeLessThanOrEqual(200);

    // A cart order per checkout, never more than the number of arrivals a
    // waiting-room ticket actually let through.
    $cartOrders = $db->table('orders')
        ->where('brand_id', $brandId)
        ->where('settles_type', 'cart')
        ->count();
    expect($cartOrders)->toBeLessThanOrEqual($admitted);

    // No seat sold twice: never more registrations for the type than its
    // own quota allows.
    $registrations = $db->table('registrations')
        ->where('registration_type_id', RUSH_TYPE_ID)
        ->count();
    expect($registrations)->toBeLessThanOrEqual(300);
})->group('load');
