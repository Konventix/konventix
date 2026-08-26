<?php

declare(strict_types=1);

use Konventix\Commerce\Gdpr\CommerceGdprHandler;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * Commerce's contribution to a subject request (ADR-0044): export by the
 * payer column, erase nothing — an issued invoice is retained under §147 AO.
 */
it('is registered with Compliance under its module name', function (): void {
    expect(app(GdprHandlerRegistry::class)->has('commerce'))->toBeTrue()
        ->and(app(GdprHandlerRegistry::class)->get('commerce'))->toBeInstanceOf(CommerceGdprHandler::class);
});

it('exports the subject\'s orders and invoices on that brand only', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $mine = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $mine->getKey(), 'invoice_number' => 'KVX-2026-00007']);
    Order::factory()->forPayer(User::factory()->create()->getKey())->create();

    $elsewhere = Brand::factory()->create();
    $this->withBrand($elsewhere);
    Order::factory()->forPayer($user->getKey())->create();
    $this->withBrand($brand);

    $export = (new CommerceGdprHandler)->exportFor($user->getKey(), $brand->getKey());

    expect($export['orders'])->toHaveCount(1)
        ->and($export['orders'][0]['id'])->toBe($mine->getKey())
        ->and($export['orders'][0]['invoices'][0]['invoice_number'])->toBe('KVX-2026-00007')
        ->and($export['orders'][0]['invoices'][0]['billing_party'])->toBeArray();
});

it('erases nothing in either deletion mode and keeps the payer link', function (DeletionMode $mode): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $order = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $order->getKey()]);

    (new CommerceGdprHandler)->delete($user->getKey(), $brand->getKey(), $mode);

    expect(Order::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($order->refresh()->payer_user_id)->toBe($user->getKey());
})->with([DeletionMode::Anonymize, DeletionMode::Erase]);
