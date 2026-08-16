<?php

declare(strict_types=1);

use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Registration\Models\RegistrationType;

/**
 * The demo seed's commerce slice runs through the real settlement chain
 * (ADR-0006): a priced Supporter registration must yield an order and an
 * invoice, and the alternating manual payment must leave both paid and
 * pending orders behind. Small dimensions keep the run fast; the interactive
 * path is used because `--default` seeds 3 brands with 100 users each.
 */
it('seeds orders, invoices and payments through the settlement chain', function () {
    // The base TestCase creates a factory brand answering on localhost;
    // BrandSeeder wants that host for the dev brand (host is globally
    // unique), so release it before running the seed.
    BrandDomain::query()->where('host', 'localhost')->delete();

    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '6')
        ->assertSuccessful();

    $brand = Brand::query()->firstWhere('slug', 'sxlan');
    expect($brand)->not->toBeNull()
        ->and($brand->operator_id)->not->toBeNull();

    // 6 registrations / 3 = 2 supporters: index 0 paid, index 1 pending.
    $orders = Order::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($orders)->toHaveCount(2)
        ->and($orders->where('status', OrderStatus::Paid))->toHaveCount(1)
        ->and($orders->where('status', OrderStatus::Pending))->toHaveCount(1);

    $invoices = Invoice::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($invoices)->toHaveCount(2)
        ->and($invoices->first()->invoice_number)->toStartWith('SXLAN-');

    // Re-running must not double-submit: commerce seeding is idempotent on
    // the Supporter type's existence, like events are on their slug.
    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '6')
        ->assertSuccessful();

    expect(Order::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(2)
        ->and(RegistrationType::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('name', 'Supporter')
            ->count())->toBe(1);
});
