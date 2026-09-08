<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\RecordRefund;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Exceptions\CreditNoteRequiresInvoiceException;
use Konventix\Commerce\Exceptions\RefundBlockedByCheckInException;
use Konventix\Commerce\Exceptions\RefundEventRelayFailedException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Records refund calls so tests can assert the PSP side of `RefundOrder`
 * (ADR-0039) without any provider SDK.
 */
final class RefundTestSpyAdapter implements SettlementAdapter
{
    /** @var list<array{accountId: string, amount: string, currency: string}> */
    public array $refunds = [];

    public bool $refuse = false;

    /** What the provider answers with (#247); null is `manual`'s answer. */
    public ?string $reference = 're_spy_1';

    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): ?string
    {
        if ($this->refuse) {
            throw new RuntimeException('provider refused the refund');
        }

        $this->refunds[] = ['accountId' => $accountId, 'amount' => $amount, 'currency' => $currency];

        // The provider's own identifier for the refund (#247).
        return $this->reference;
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
            && $e->creditNoteId !== null
            && $e->whole === true,
    );
    Event::assertDispatched(CreditNoteIssued::class);
});

it('flags a partial refund as not whole, so Registration knows the order still owes', function (): void {
    // ADR-0057 point 5: `OrderRefunded.whole` is the only way Registration
    // can tell a full refund from a partial one — the amount alone cannot.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $partialAmount = bcdiv((string) $order->total_gross, '2', 2);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request', amount: $partialAmount);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->amountRefunded === $partialAmount
            && $e->whole === false,
    );
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

it('refunds an unapplied payment on a cancelled order, without a credit note (#6)', function (): void {
    // ADR-0057 point 9: the PSP success that landed after a sweep had
    // already cancelled the order. The money moved and paid for nothing;
    // sending it back is the provider leg alone — there is no document to
    // correct and no sale to undo.
    $this->withBrand();

    $order = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'settles_type' => 'cart',
        'total_gross' => '119.00',
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => (string) Str::ulid(),
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $reversal = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($spy->refunds)->toHaveCount(1)
        ->and($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->provider_reference)->toBe('re_spy_1')
        // The order is not "refunded": nothing was sold to undo.
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        // And the money leaves the console's "decide about this" list.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(Payment::withoutBrandScope()->unapplied()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

it('refuses a second refund of unapplied money the first one already sent back (#6)', function (): void {
    $this->withBrand();

    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'total_gross' => '119.00']);

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => '119.00',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    // The payment is `reversed` now, so there is no unapplied money left and
    // the guard refuses — the operator pressing the button twice cannot send
    // the money back twice.
    expect(fn () => app(RefundOrder::class)->handle($order->refresh(), reasonCode: 'customer-request'))
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

it('records the provider\'s own reference for the refund on a reversal row (#247)', function (): void {
    // Before this the `re_…` never left the adapter: after a refund no path
    // led from the order to the money's identifier at the provider, and the
    // "already refunded" branch of #236 could only tell the operator to go
    // and look in the dashboard.
    $this->withBrand();

    $order = refundTestPaidOrder();
    $accountId = (string) Str::ulid();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => $accountId,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    $reversal = PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($reversal->kind)->toBe(ReversalKind::Refund)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and($reversal->provider_reference)->toBe('re_spy_1')
        ->and($reversal->payment_id)->toBe($payment->id)
        ->and($reversal->settlement_account_id)->toBe($accountId)
        ->and($reversal->brand_id)->toBe($order->brand_id)
        ->and((string) $reversal->amount)->toBe((string) $order->total_gross)
        // The refund's own consequences stay exactly where they were: the
        // row carries the reference, it does not re-run the refund.
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('lets the provider notification own the paperwork when it wins the race (#247)', function (): void {
    // `charge.refunded` for our own refund can be delivered between the API
    // call returning `re_…` and this transaction committing (review finding
    // 5, NEW-3). The provider's path issues its own credit note and
    // `OrderRefunded`, so the unique key on the reversal row decides who
    // does that work: the winner does it, the loser stops. Two credit notes
    // for one refund is paperwork nobody can undo (ADR-0031).
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    // The notification lands the moment `RecordOwnRefund` looks for its row
    // — the whole webhook path, not just the row, because that is what a
    // real one does.
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $payment): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'payment_reversals')) {
            return;
        }

        $raced = true;

        app(RecordPaymentReversal::class)->handle(
            payment: $payment->fresh(),
            kind: ReversalKind::ExternalRefund,
            status: ReversalStatus::Final,
            amount: (string) $payment->amount,
            currency: $payment->currency,
            providerReference: 're_spy_1',
            reasonCode: null,
            feeAmount: null,
            occurredAt: now(),
        );
    });

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect($raced)->toBeTrue()
        // One row, one credit note, one announcement — the provider's.
        ->and(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::ExternalRefund)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('keeps the paperwork to itself when the console wins the race (#247)', function (): void {
    // The other ordering: our row is committed first, so the provider's
    // notification for the same `re_…` converges on it and does nothing —
    // no second credit note, no second `OrderRefunded`.
    $this->withBrand();

    $order = refundTestPaidOrder();

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    app()->instance(RefundTestSpyAdapter::class, app(RefundTestSpyAdapter::class));

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund);

    app(RecordPaymentReversal::class)->handle(
        payment: $payment->fresh(),
        kind: ReversalKind::ExternalRefund,
        status: ReversalStatus::Final,
        amount: (string) $payment->amount,
        currency: $payment->currency,
        providerReference: 're_spy_1',
        reasonCode: null,
        feeAmount: null,
        occurredAt: now(),
    );

    expect(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->kind)->toBe(ReversalKind::Refund)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('records no reversal row for a refund no provider gave a reference to (#247)', function (): void {
    // `manual` wires the money back outside the platform: there is no
    // reference, and a row whose `provider_reference` is invented would
    // collide with the unique key the reversal table is built on.
    $this->withBrand();

    $order = refundTestPaidOrder();

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', RefundTestSpyAdapter::class);
    $spy = app(RefundTestSpyAdapter::class);
    $spy->reference = null;
    app()->instance(RefundTestSpyAdapter::class, $spy);

    Event::fake([OrderRefunded::class, CreditNoteIssued::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    expect(PaymentReversal::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
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

    // Never reached: the provider call comes before any domain write, so no
    // record can claim money moved that did not (#236 kept this order).
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});

/**
 * #236: the money left Stripe, then the `order_refunded` consumer threw
 * inside the console request — and because the provider call and the domain
 * record shared one transaction with the consumers, the rollback undid the
 * credit note and the `refunded` status while the refund stood at the
 * provider. The order was left `paid` with the money gone.
 *
 * The recorded refund is committed first and the domain events go out after
 * the commit: a failing consumer is still an error the operator sees, but it
 * can no longer erase what the provider already did.
 */
it('keeps the recorded refund when a consumer of OrderRefunded throws (#236)', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    Event::listen(OrderRefunded::class, function (): void {
        throw new RuntimeException('the consumer blew up');
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'the consumer blew up');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);
});

/**
 * #236 fix round 1, review finding 3: a plain `foreach` over the events
 * stopped at the first throwing consumer, so on a three-line cart order
 * lines 2 and 3 never heard about the refund at all — they stayed confirmed
 * with their quota consumed, and nothing rolled back or retried them. Each
 * line is dispatched on its own now; the failures are collected and rethrown
 * as one, so the job fails (and Horizon retries it) without any line being
 * skipped silently.
 */
it('dispatches every line even when the first consumer throws (#236)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    $first = (string) Str::ulid();
    $second = (string) Str::ulid();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => $first,
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => $second,
    ]);

    $seen = [];

    Event::listen(OrderLineCancelled::class, function (OrderLineCancelled $event) use (&$seen, $first): void {
        $seen[] = $event->refId;

        if ($event->refId === $first) {
            throw new RuntimeException('the first line\'s consumer blew up');
        }
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RefundEventRelayFailedException::class);

    // Both lines heard about it, in position order, and the refund stands.
    expect($seen)->toBe([$first, $second])
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('keeps the recorded refund when a consumer of OrderLineCancelled throws (#236)', function (): void {
    // The cart order's per-line leg, which is the one that actually failed
    // on dev: `CancelRegistrationOnOrderLineCancelled` runs on this event.
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::listen(OrderLineCancelled::class, function (): void {
        throw new RuntimeException('the line consumer blew up');
    });

    expect(fn () => app(RefundOrder::class)->handle($order, reasonCode: 'customer-request'))
        ->toThrow(RuntimeException::class, 'the line consumer blew up');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1);
});

/**
 * A brand with an operator attached — `IssueInvoiceOnOrderPaid`'s document
 * pipeline needs a seller to render (`OperatorSettings::sellerForBrand()`).
 * Duplicated from `PaidInvoiceTest::paidInvoiceBrand()` rather than reused:
 * Pest helper functions only exist once their defining file has been loaded
 * by the current run, so a file that must pass in isolation (`php artisan
 * test app-modules/commerce/tests/RefundAndCreditNoteTest.php` alone) cannot
 * call a helper defined in a sibling test file (.ai/rules/tests.md).
 */
function refundTestBrandWithOperator(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function refundTestBillingParty(?string $name = 'Erika Musterfrau', ?string $email = 'erika@musterfrau.example'): BillingPartyData
{
    return BillingPartyData::ofAccount((string) $name, (string) $email);
}

/**
 * A pending cart order with one line and a billing party (Task 2's
 * `OrderPaid` → paid Rechnung shape) — ready for `RecordPaymentSuccess` to
 * settle and, from there, for `RefundOrder` to reverse.
 */
function refundTestCartOrder(?BillingPartyData $billingParty = null): Order
{
    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
        'billing_party' => $billingParty ?? refundTestBillingParty(),
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Rush Shirt',
        'unit_amount_net' => '100.00',
        'tax_rate' => '19.00',
        'line_net' => '100.00',
        'line_tax' => '19.00',
        'line_gross' => '119.00',
    ]);

    return $order;
}

function refundTestCardPayment(Order $order, string $provider = 'stripe'): Payment
{
    return Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => $provider,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);
}

/**
 * End to end (Task 3, ADR-0048 point 2): a card-paid cart order (Task 2's
 * `OrderPaid` → paid Rechnung setup) refunded through `RefundOrder` leaves a
 * Gutschrift the payer can both read about and download — not just a
 * `CreditNote` row.
 *
 * `GenerateCreditNoteDocument` is never dispatched by hand here: it runs
 * `afterCommit()` from inside `IssueCreditNote`'s own transaction, which
 * under RefreshDatabase never reaches transaction level 0 — so the dispatch
 * runs synchronously, in-process, by the time `RefundOrder::handle()`
 * returns (`.ai/rules/tests.md`). `Storage::fake('invoices')` here is a
 * second, harmless fake of the disk `TestCase::setUp()` already faked
 * globally — kept explicit so this test's intent reads on its own.
 */
it('refunds a paid cart order into a mailed, downloadable Gutschrift', function (): void {
    $brand = refundTestBrandWithOperator();
    Storage::fake('invoices');
    Mail::fake();

    $payer = User::factory()->create();
    $order = refundTestCartOrder(refundTestBillingParty($payer->name, $payer->email));
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    // manual: RefundOrder's PSP call resolves ManualSettlementAdapter, a
    // true no-op (ADR-0039) — no live provider call in a test.
    $payment = refundTestCardPayment($order, 'manual');
    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    expect($invoice->paid_at)->not->toBeNull();

    app(RefundOrder::class)->handle($order->fresh(), reasonCode: 'customer-request');

    $note = CreditNote::query()->where('order_id', $order->id)->sole();

    expect($note->document_path)->not->toBeNull()
        ->and($note->document_disk)->toBe('invoices');

    Storage::disk('invoices')->assertExists($note->document_path);

    // Two TemplatedMails go out here — the paid Rechnung (Task 2) and the
    // Gutschrift (this task) — so the matcher must pick its own out by
    // subject before asserting on it, not assert unconditionally: a failed
    // `expect()` inside `assertSent`'s callback throws rather than counting
    // as "no match, try the next mail".
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($note): bool {
        if (! str_contains($mail->envelope()->subject, $note->credit_note_number)) {
            return false;
        }

        $attachments = $mail->attachments();

        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->as)->toBe($note->credit_note_number.'.pdf');

        return true;
    });

    test()->actingAs($payer)
        ->get('/my/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commerce/my/Index')
            ->where('orders.0.creditNotes.0.number', $note->credit_note_number)
            ->where(
                'orders.0.creditNotes.0.documentUrl',
                fn (?string $url): bool => $url !== null && str_contains($url, 'signature='),
            ));
});

/**
 * Whole-branch review finding 3. `RecordRefund` emitted `OrderRefunded` and
 * nothing else, and `CancelRegistrationOnOrderUndone` ignores a `cart`
 * order — so refunding a cart order that paid for a registration sent the
 * money back and left the place, the quota and the admission standing,
 * while the same button on a direct registration order released them. The
 * per-line event `CancelOrderForUnpaidReversal` already emits for exactly
 * this reason is now emitted here too.
 */
function refundTestPaidCartOrder(): Order
{
    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
    ]);

    Invoice::factory()->create(['order_id' => $order->id]);

    return $order;
}

it('releases a cart order\'s registration lines on a whole refund (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    $registrationLine = OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);
    $productLine = OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 2,
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::fake([OrderRefunded::class, OrderLineCancelled::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    // One event, for the registration line only: a product line is
    // Commerce's own business.
    Event::assertDispatchedTimes(OrderLineCancelled::class, 1);
    Event::assertDispatched(
        OrderLineCancelled::class,
        fn (OrderLineCancelled $e): bool => $e->orderId === $order->id
            && $e->kind === 'registration'
            && $e->refId === $registrationLine->settles_ref
            && $e->reasonCode === RecordRefund::REASON_ORDER_REFUNDED
            && $e->cancelledByUserId === null,
    );

    expect($productLine->settles_ref)->not->toBe($registrationLine->settles_ref);
});

it('releases nothing on a partial refund of a cart order (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidCartOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::fake([OrderLineCancelled::class]);

    app(RefundOrder::class)->handle(
        $order,
        reasonCode: 'goodwill',
        amount: bcdiv((string) $order->total_gross, '2', 2),
    );

    Event::assertNotDispatched(OrderLineCancelled::class);
});

it('emits no line event for a direct registration order, which OrderRefunded already names (#73)', function (): void {
    $this->withBrand();

    $order = refundTestPaidOrder();

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => $order->settles_id,
    ]);

    Event::fake([OrderLineCancelled::class]);

    app(RefundOrder::class)->handle($order, reasonCode: 'customer-request');

    Event::assertNotDispatched(OrderLineCancelled::class);
});
