<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;

/**
 * Records refund calls so tests can assert the PSP side of `RefundOrder`
 * (ADR-0039) without any provider SDK.
 */
final class RefundTestSpyAdapter implements SettlementAdapter
{
    /** @var list<array{accountId: string, amount: string, currency: string}> */
    public array $refunds = [];

    public bool $refuse = false;

    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): void
    {
        if ($this->refuse) {
            throw new RuntimeException('provider refused the refund');
        }

        $this->refunds[] = ['accountId' => $accountId, 'amount' => $amount, 'currency' => $currency];
    }
}

/**
 * Refunds blocked by check-in (ADR-0037, D-COM-15) and the credit notes that
 * document them (ADR-0031, ADR-0036).
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function refundTestPaidOrder(?string $registrationId = null, bool $invoiced = true): Order
{
    $order = Order::factory()->paid()->create([
        'settles_type' => 'registration',
        'settles_id' => $registrationId ?? (string) Str::ulid(),
    ]);

    // A credit note corrects an *invoice* (ADR-0031), so most of these cases
    // need one. `$invoiced: false` is the instant-settled order that was paid
    // at checkout and never invoiced.
    if ($invoiced) {
        Invoice::factory()->create(['order_id' => $order->id]);
    }

    return $order;
}

function refundTestCheckIn(Order $order, ?string $admissionId = null, string $direction = 'in'): AdmissionCheckedIn
{
    return new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId ?? (string) Str::ulid(),
        registrationId: $order->settles_id,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: $direction,
        scannedAt: now()->toIso8601String(),
    );
}

it('refunds a paid order and issues a credit note documenting it', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    $refunded = app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($refunded->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->settlesId === $order->settles_id
            && $e->creditNoteId !== null,
    );
    Event::assertDispatched(CreditNoteIssued::class);
});

it('blocks the refund when the registration has a checked-in admission', function (): void {
    // D-COM-15, the user's override of the report-only recommendation.
    $this->withBrand();

    $order = refundTestPaidOrder();

    // Arrives the only way it may: through Admission's event, never a join.
    event(refundTestCheckIn($order));

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundBlockedByCheckInException::class);

    // An explicit refusal, not a silent no-op — nothing moved.
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('answers the block from its own read model, never from Admission tables', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    event(refundTestCheckIn($order));

    expect(CheckedInAdmission::registrationHasCheckIn($order->brand_id, $order->settles_id))->toBeTrue()
        ->and(CheckedInAdmission::query()->where('registration_id', $order->settles_id)->count())->toBe(1);
});

it('converges under replay instead of double-counting', function (): void {
    // An offline device reconciles late (ADR-0033), so redelivery is normal.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $admissionId = (string) Str::ulid();

    event(refundTestCheckIn($order, $admissionId));
    event(refundTestCheckIn($order, $admissionId));

    expect(CheckedInAdmission::query()->where('admission_id', $admissionId)->count())->toBe(1);
});

it('keeps the earliest check-in when events arrive out of order', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    $admissionId = (string) Str::ulid();

    $late = refundTestCheckIn($order, $admissionId);
    event($late);

    $earlier = new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: $order->settles_id,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->subHours(3)->toIso8601String(),
    );
    event($earlier);

    $row = CheckedInAdmission::query()->where('admission_id', $admissionId)->firstOrFail();

    expect($row->first_checked_in_at->toIso8601String())->toBe(
        now()->subHours(3)->startOfSecond()->toIso8601String(),
    );
});

it('ignores check-outs, which say nothing a check-in has not already said', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();
    event(refundTestCheckIn($order, direction: 'out'));

    expect(CheckedInAdmission::query()->count())->toBe(0);

    // So the refund is not blocked by an exit alone.
    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('does not block an order that settles something other than a registration', function (): void {
    // Only registrations have admissions; nothing else can be checked in.
    $this->withBrand();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'resource_booking',
        'settles_id' => (string) Str::ulid(),
    ]);
    Invoice::factory()->create(['order_id' => $order->id]);

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('refuses to refund an order that was never paid', function (): void {
    $this->withBrand();

    $order = Order::factory()->create(['settles_type' => 'registration']);

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class);
});

it('draws credit-note numbers from their own gapless series', function (): void {
    // A separate sequence from invoices, so a tax advisor can tell the two
    // document kinds apart by the number alone.
    $this->withBrand();

    $first = app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_REFUND);
    $second = app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_TRANSFER);

    expect($first->credit_note_number)->toStartWith('GS-')
        ->and($first->credit_note_number)->toEndWith('-00001')
        ->and($second->credit_note_number)->toEndWith('-00002');
});

it('marks a transfer credit note distinctly from a refund one', function (): void {
    // ADR-0036's post-invoice transfer issues the document without money
    // moving; a consumer counting refunds must be able to tell them apart.
    $this->withBrand();

    Event::fake([CreditNoteIssued::class, OrderRefunded::class]);

    app(IssueCreditNote::class)->handle(refundTestPaidOrder(), IssueCreditNote::REASON_TRANSFER);

    Event::assertDispatched(
        CreditNoteIssued::class,
        fn (CreditNoteIssued $e): bool => $e->reasonCode === IssueCreditNote::REASON_TRANSFER,
    );
    Event::assertNotDispatched(OrderRefunded::class);
});

it('refunds an uninvoiced order without producing paperwork', function (): void {
    // An instant-settled order paid at checkout has no invoice, so there is
    // nothing for a credit note to correct — the money still goes back.
    $this->withBrand();

    $order = refundTestPaidOrder(invoiced: false);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->creditNoteId === null);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

it('blocks the refund of a cart order when one of its registration lines is checked in', function (): void {
    // ADR-0047: a cart order settles a mix of products and registrations —
    // the check-in guard has to look through settles_kind lines, not just
    // settles_id, for a cart order to be covered at all.
    test()->withBrand();

    $registrationId = (string) Str::ulid();
    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => $registrationId,
    ]);
    Invoice::factory()->create(['order_id' => $order->id]);

    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: (string) Str::ulid(),
        registrationId: $registrationId,
        eventId: (string) Str::ulid(),
        brandId: $order->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundBlockedByCheckInException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('refunds a cart order whose lines have no checked-in registration', function (): void {
    test()->withBrand();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    expect(app(RefundOrder::class)->handle($order, reasonCode: 'customer-request')->status)
        ->toBe(OrderStatus::Refunded);
});

it('refuses to credit-note an order that was never invoiced', function (): void {
    $this->withBrand();

    expect(fn () => app(IssueCreditNote::class)->handle(
        refundTestPaidOrder(invoiced: false),
        IssueCreditNote::REASON_REFUND,
    ))->toThrow(CreditNoteRequiresInvoiceException::class);
});

it('sends the PSP refund through the provider that took the payment', function (): void {
    // ADR-0039: the money goes back via the order's settled payment, not the
    // brand's *current* live account, which may have changed since.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $accountId = (string) Str::ulid();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => $accountId,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($spy->refunds)->toHaveCount(1)
        ->and($spy->refunds[0]['accountId'])->toBe($accountId)
        ->and($spy->refunds[0]['amount'])->toBe((string) $order->total_gross);
});

it('aborts the refund entirely when the provider refuses it', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => (string) Str::ulid(),
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    $spy->refuse = true;
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class]);

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'provider refused');

    // Rolled back: no domain record may claim money moved that did not.
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});
