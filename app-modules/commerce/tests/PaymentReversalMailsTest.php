<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * ADR-0057 point 8: "Operator and payer both see it." The operator gets the
 * `commerce.operations-notice` kinds `dispute-opened`, `payment-reversed`
 * and `payment-unapplied`; the payer gets `commerce.payment-reversed` — the
 * amount, the provider's reason in plain words, the deadline and the pay
 * link. "The reason strings are bank and network codes; the mail informs,
 * it does not accuse."
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function reversalMailsRecipient(string $brandId, ?string $locale = null): User
{
    $user = User::factory()->create();

    // Messaging's recipient read model has no factory — rows exist only
    // through `ProjectRecipient`'s projection (`Recipient`'s own docblock).
    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $locale ?? $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    return $user;
}

function reversalMailsOperator(string $brandId): User
{
    PermissionModel::query()->firstOrCreate(['name' => 'commerce.manage-products', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Reversal Manager', 'guard_name' => 'web', 'brand_id' => $brandId]);
    $role->givePermissionTo(['commerce.manage-products']);

    $user = reversalMailsRecipient($brandId);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

function reversalMailsEvent(Order $order, string $reasonCode = 'AM04', ?string $repayDueOn = null): PaymentReversed
{
    return new PaymentReversed(
        reversalId: (string) Str::ulid(),
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $order->brand_id,
        settlesType: $order->settles_type,
        settlesId: $order->settles_id,
        kind: 'return',
        amount: '119.00',
        currency: 'EUR',
        settlementMethod: 'mollie',
        settlementAccountId: null,
        providerReference: 'chb_test_1',
        reasonCode: $reasonCode,
        repayDueOn: $repayDueOn ?? now()->addDays(7)->toDateString(),
        reversedAt: now()->toIso8601String(),
    );
}

function reversalMailsRecorded(Order $order, string $kind, string $status): PaymentReversalRecorded
{
    return new PaymentReversalRecorded(
        reversalId: (string) Str::ulid(),
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $order->brand_id,
        kind: $kind,
        status: $status,
        amount: '119.00',
        currency: 'EUR',
        provider: 'stripe',
        providerReference: 'dp_test_1',
        reasonCode: 'insufficient_funds',
        occurredAt: now()->toIso8601String(),
    );
}

it('tells the payer their money was taken back, with the deadline and a pay link', function (): void {
    $brand = $this->withBrand();
    $payer = reversalMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'registration', 'payer_user_id' => $payer->id]);
    $dueOn = now()->addDays(7);

    event(reversalMailsEvent($order, repayDueOn: $dueOn->toDateString()));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($order, $payer, $dueOn): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.payment-reversed'
            && $job->message->recipientId === $payer->id
            && $payload['orderNumberShort'] === strtoupper(substr($order->id, -8))
            && $payload['amount'] === '119.00'
            && $payload['currency'] === 'EUR'
            && $payload['dueOn'] === $dueOn->format('d.m.Y')
            && str_contains($payload['payUrl'], '/pay/registration/'.$order->settles_id)
            && str_ends_with($payload['ordersUrl'], '/my/orders');
    });
});

it('maps a known return code to a plain, non-accusatory sentence in both languages', function (): void {
    $brand = $this->withBrand();
    $payer = reversalMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => $payer->id]);

    event(reversalMailsEvent($order, reasonCode: 'AM04'));

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-reversed',
    )->first();

    $payload = $job->message->payload->toArray();

    expect($payload['reasonDe'])->toContain('gedeckt')
        ->and($payload['reasonEn'])->toContain('funds')
        // "informs, does not accuse": no second person, no blame.
        ->and($payload['reasonDe'])->not->toContain('Sie haben')
        ->and($payload['payUrl'])->toEndWith('/my/orders');
});

it('falls back to a neutral sentence for a reason code it does not know', function (): void {
    $brand = $this->withBrand();
    $payer = reversalMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => $payer->id]);

    event(reversalMailsEvent($order, reasonCode: 'ZZ99'));

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-reversed',
    )->first();

    $payload = $job->message->payload->toArray();

    expect($payload['reasonDe'])->not->toContain('ZZ99')
        ->and($payload['reasonDe'])->not->toBe('')
        ->and($payload['reasonEn'])->not->toBe('');
});

it('renders the reversal mail in the payer\'s own locale', function (): void {
    $brand = $this->withBrand();
    $payer = reversalMailsRecipient($brand->id, 'en');
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => $payer->id]);

    event(reversalMailsEvent($order));

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-reversed',
    )->sole();

    expect($job->message->recipientLocale)->toBe('en')
        ->and(app(TemplateRenderer::class)->render($job->message)->subject)->toContain('was reversed');
});

it('sends no payer mail for an order with no payer', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(reversalMailsEvent($order));

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('tells the operators a dispute was opened, without a payer mail', function (): void {
    $brand = $this->withBrand();
    $holder = reversalMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(reversalMailsRecorded($order, kind: 'dispute', status: 'open'));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $payload['kind'] === 'dispute-opened';
    });

    Queue::assertNotPushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-reversed',
    );
});

it('tells the operators a reversal became final', function (): void {
    $brand = $this->withBrand();
    $holder = reversalMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(reversalMailsRecorded($order, kind: 'return', status: 'final'));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $payload['kind'] === 'payment-reversed';
    });
});

it('says nothing at all about a dispute the organizer won', function (): void {
    $brand = $this->withBrand();
    reversalMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(reversalMailsRecorded($order, kind: 'dispute', status: 'withdrawn'));

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('says nothing about an external refund, whose own path already reports it', function (): void {
    $brand = $this->withBrand();
    reversalMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->paid()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(reversalMailsRecorded($order, kind: 'external_refund', status: 'final'));

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('tells the operators about money that arrived for a cancelled order', function (): void {
    $brand = $this->withBrand();
    $holder = reversalMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(new PaymentUnapplied(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        amount: '119.00',
        currency: 'EUR',
        provider: 'stripe',
        settlementAccountId: null,
        receivedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $payload['kind'] === 'payment-unapplied';
    });
});
