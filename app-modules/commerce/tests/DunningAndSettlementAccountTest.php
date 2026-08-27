<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Actions\RaiseDunningLevel;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Commerce\Events\InvoiceOverdue;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;

/**
 * The dunning sweep and the settlement-account/adapter seam (ADR-0007).
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function dunningTestInvoice(int $daysOverdue, OrderStatus $status = OrderStatus::Pending): Invoice
{
    $order = Order::factory()->create(['status' => $status]);

    return Invoice::factory()->create([
        'order_id' => $order->id,
        'due_on' => now()->subDays($daysOverdue),
    ]);
}

it('flags an overdue invoice exactly once, however often the sweep runs', function (): void {
    // The sweep is daily; re-emitting would turn one unpaid invoice into a
    // daily notification about the same unchanging fact.
    $this->withBrand();

    dunningTestInvoice(daysOverdue: 3);

    Event::fake([InvoiceOverdue::class]);

    expect(app(RaiseDunningLevel::class)->handle()['overdue'])->toBe(1);
    expect(app(RaiseDunningLevel::class)->handle()['overdue'])->toBe(0);

    Event::assertDispatchedTimes(InvoiceOverdue::class, 1);
});

it('leaves an invoice that is not yet due alone', function (): void {
    $this->withBrand();

    Invoice::factory()->create([
        'order_id' => Order::factory()->create()->id,
        'due_on' => now()->addWeek(),
    ]);

    expect(app(RaiseDunningLevel::class)->handle())->toBe(['overdue' => 0, 'raised' => 0]);
});

it('never duns a paid, cancelled or refunded order', function (): void {
    // Chasing money that already arrived is the failure mode that erodes
    // trust in an automated reminder faster than anything else.
    $this->withBrand();

    dunningTestInvoice(daysOverdue: 30, status: OrderStatus::Paid);
    dunningTestInvoice(daysOverdue: 30, status: OrderStatus::Cancelled);
    dunningTestInvoice(daysOverdue: 30, status: OrderStatus::Refunded);

    expect(app(RaiseDunningLevel::class)->handle())->toBe(['overdue' => 0, 'raised' => 0]);
});

it('escalates through the day thresholds and stops at the top', function (): void {
    $this->withBrand();

    $invoice = dunningTestInvoice(daysOverdue: 3);

    app(RaiseDunningLevel::class)->handle();
    expect($invoice->fresh()->dunning_level)->toBe(0);

    $invoice->forceFill(['due_on' => now()->subDays(8)])->save();
    app(RaiseDunningLevel::class)->handle();
    expect($invoice->fresh()->dunning_level)->toBe(1);

    $invoice->forceFill(['due_on' => now()->subDays(50)])->save();
    app(RaiseDunningLevel::class)->handle();
    expect($invoice->fresh()->dunning_level)->toBe(3);

    // A year overdue is still level 3 — the ladder ends.
    $invoice->forceFill(['due_on' => now()->subYear()])->save();
    app(RaiseDunningLevel::class)->handle();
    expect($invoice->fresh()->dunning_level)->toBe(3);
});

it('emits DunningLevelRaised only when the level actually moves', function (): void {
    $this->withBrand();

    $invoice = dunningTestInvoice(daysOverdue: 10);

    Event::fake([DunningLevelRaised::class]);

    app(RaiseDunningLevel::class)->handle();
    app(RaiseDunningLevel::class)->handle();

    Event::assertDispatchedTimes(DunningLevelRaised::class, 1);
    Event::assertDispatched(
        DunningLevelRaised::class,
        fn (DunningLevelRaised $e): bool => $e->invoiceId === $invoice->id && $e->level === 1,
    );
});

it('sends no mail itself — Messaging owns what a level means', function (): void {
    // The same split D-CNT-08 draws for announcements: Commerce declares that
    // a level was reached, Messaging decides channel, wording and timing.
    $source = file_get_contents(
        base_path('app-modules/commerce/src/Actions/RaiseDunningLevel.php')
    );

    expect($source)->not->toContain('SendMessage')
        ->and($source)->not->toContain('Mail::');
});

it('runs from the scheduled console command', function (): void {
    $this->withBrand();

    dunningTestInvoice(daysOverdue: 9);

    $this->artisan('commerce:dunning')
        ->expectsOutputToContain('1 newly overdue')
        ->assertSuccessful();
});

it('refuses to invent an adapter for an unregistered provider', function (): void {
    // Slice 7b registers Stripe and Mollie here; until then, asking for one
    // must fail loudly rather than silently settle through something else.
    expect(fn () => app(SettlementAdapterRegistry::class)->forProvider('stripe'))
        ->toThrow(RuntimeException::class);
});

it('allows one live settlement account per provider per brand', function (): void {
    // Two providers create fine (ADR-0046 reverses the single-answer rule);
    // the same provider twice still has no defined answer for which live row
    // an order settles through, so the database refuses that state.
    $this->withBrand();

    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create();
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();

    expect(fn () => SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_STRIPE)->create())
        ->toThrow(QueryException::class);
});
