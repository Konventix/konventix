<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * ADR-0048 point 3: Commerce owns the mail behind its own events — a cart
 * checkout's order confirmation, a failed payment explained to the payer,
 * and the shop manager told about a sold-out variant or a payment that did
 * not go through, on the `commerce.operations` topic.
 */
function orderMailsRecipient(string $brandId): User
{
    $user = User::factory()->create();

    // Messaging's recipient read model has no factory of its own — rows
    // exist only through `ProjectRecipient`'s projection, so a test that
    // needs `SendMessage` to resolve an address emits the Identity event
    // instead (`Recipient`'s own docblock).
    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        locale: $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    return $user;
}

function orderMailsOperator(string $brandId, array $permissions = ['commerce.manage-products'], string $roleName = 'Shop Manager'): User
{
    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => $roleName,
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
    $role->givePermissionTo($permissions);

    $user = orderMailsRecipient($brandId);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

it('sends the order confirmation when a cart order is placed, with lines and the reservation window', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'payer_user_id' => $payer->id,
        'total_net' => '30.00',
        'total_gross' => '35.70',
        'currency' => 'EUR',
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Con Shirt',
        'quantity' => 2,
        'line_gross' => '35.70',
    ]);

    $cart = Cart::factory()->create([
        'payer_user_id' => $payer->id,
        'checked_out_order_id' => $order->id,
        'expires_at' => now()->addHour(),
    ]);

    event(new OrderPlaced(
        orderId: $order->id,
        brandId: $brand->id,
        settlesType: 'cart',
        settlesId: $cart->id,
        totalNet: '30.00',
        totalGross: '35.70',
        currency: 'EUR',
        placedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($order, $payer): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.order-confirmation'
            && $job->message->recipientId === $payer->id
            && $payload['orderNumberShort'] === strtoupper(substr($order->id, -8))
            && $payload['totalGross'] === '35.70'
            && $payload['currency'] === 'EUR'
            && $payload['paid'] === false
            && $payload['reservationUntil'] !== null
            && count($payload['lines']) === 1
            && $payload['lines'][0]['label'] === 'Con Shirt'
            && $payload['lines'][0]['quantity'] === 2
            && $payload['lines'][0]['lineGross'] === '35.70'
            && str_ends_with($payload['ordersUrl'], '/my/orders');
    });
});

it('sends no order confirmation for a direct registration order', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'registration', 'payer_user_id' => $payer->id]);

    event(new OrderPlaced(
        orderId: $order->id,
        brandId: $brand->id,
        settlesType: 'registration',
        settlesId: $order->settles_id,
        totalNet: '30.00',
        totalGross: '35.70',
        currency: 'EUR',
        placedAt: now()->toIso8601String(),
    ));

    Queue::assertNotPushed(SendQueuedMessage::class);
});

it('sends the payment-failed mail to the order payer with a mapped reason and a retry link', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'cart', 'payer_user_id' => $payer->id]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'stripe',
        reasonCode: 'checkout_expired',
        failedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($order, $payer): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.payment-failed'
            && $job->message->recipientId === $payer->id
            && $payload['orderNumberShort'] === strtoupper(substr($order->id, -8))
            && $payload['reason'] === 'Die Zahlung ist abgelaufen.'
            && str_ends_with($payload['retryUrl'], '/my/orders');
    });
});

it('picks the registration checkout as the retry link for a registration-settled order', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'registration', 'payer_user_id' => $payer->id]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'mollie',
        reasonCode: 'canceled',
        failedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($order): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.payment-failed'
            && $payload['reason'] === 'Die Zahlung wurde abgebrochen.'
            && str_contains($payload['retryUrl'], '/pay/registration/'.$order->settles_id);
    });
});

it('logs a warning and sends nothing when a failed payment has no payer', function (): void {
    $brand = $this->withBrand();
    Queue::fake();
    Log::spy();

    $order = Order::factory()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'stripe',
        reasonCode: 'failed',
        failedAt: now()->toIso8601String(),
    ));

    // No permission holders are set up on this brand, so the operators'
    // notice (which runs regardless of the payer) pushes nothing either —
    // the assertion covers both listeners on `PaymentFailed`.
    Queue::assertNotPushed(SendQueuedMessage::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no payer')
            && $context['order_id'] === $order->id);
});

it('reaches exactly the permission holders with a stock-exhausted operations notice', function (): void {
    $brand = $this->withBrand();

    $product = Product::factory()->create(['name' => 'Con Shirt']);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => 'L']);

    $holder = orderMailsOperator($brand->id);
    // Holds a different capability on the same brand — not a holder of
    // `commerce.manage-products`, so must not receive the notice.
    orderMailsOperator($brand->id, ['commerce.view-orders'], 'Viewer');

    Queue::fake();

    event(new StockExhausted(
        variantId: $variant->id,
        productId: $product->id,
        brandId: $brand->id,
        exhaustedAt: now()->toIso8601String(),
    ));

    // `commerce.operations`'s two default channels (mail, in-app) each
    // produce their own envelope (`SendMessage::channelsFor()`), so one
    // holder yields two jobs here — both must be that holder's, none the
    // non-holder's.
    $notices = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.operations-notice',
    );

    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('message.recipientId')->unique()->all())->toBe([$holder->id]);

    $payload = $notices->first()->message->payload->toArray();

    expect($payload['kind'])->toBe('stock-exhausted')
        ->and($payload['title'])->toBe('Ausverkauft: Con Shirt – L')
        ->and($notices->first()->message->topicKey)->toBe('commerce.operations')
        ->and($notices->first()->message->urgent)->toBeTrue();
});

it('reaches the permission holders with a payment-failed operations notice', function (): void {
    $brand = $this->withBrand();
    $holder = orderMailsOperator($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'cart', 'payer_user_id' => null]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'stripe',
        reasonCode: 'failed',
        failedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($order, $holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $job->message->topicKey === 'commerce.operations'
            && $payload['kind'] === 'payment-failed'
            && str_contains($payload['title'], strtoupper(substr($order->id, -8)));
    });
});

it('records an in-app inbox row for the operations notice', function (): void {
    $brand = $this->withBrand();

    $product = Product::factory()->create(['name' => 'Con Mug']);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => null]);

    $holder = orderMailsOperator($brand->id);

    event(new StockExhausted(
        variantId: $variant->id,
        productId: $product->id,
        brandId: $brand->id,
        exhaustedAt: now()->toIso8601String(),
    ));

    $inbox = InboxNotification::withoutBrandScope()->where('recipient_id', $holder->id)->sole();

    expect($inbox->title)->toBe('Ausverkauft: Con Mug')
        ->and($inbox->topic_key)->toBe('commerce.operations');
});
