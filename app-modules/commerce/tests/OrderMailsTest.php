<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Data\OrderConfirmationMailData;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\UpdateBrandMailSettings;
use Konventix\Messaging\Data\MailSettingsData;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * ADR-0048 point 3: Commerce owns the mail behind its own events — a cart
 * checkout's order confirmation, a failed payment explained to the payer,
 * and the shop manager told about a sold-out variant or a payment that did
 * not go through, on the `commerce.operations` topic.
 */
function orderMailsOperator(string $brandId, array $permissions = ['commerce.manage-products'], string $roleName = 'Shop Manager', ?string $locale = null): User
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

    $user = orderMailsRecipient($brandId, $locale);

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
            && str_ends_with($payload['ordersUrl'], '/my/orders')
            // Task 9 of the 2026-09-21 bugfix round: the mail is about this
            // order, so it links the order's own page beside the list.
            && str_ends_with($payload['orderUrl'], '/my/orders/'.$order->id);
    });
});

function orderMailsConfirmationBody(?string $recipientName, string $brandId): string
{
    $message = new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: $brandId,
        recipientId: (string) Str::ulid(),
        recipientEmail: 'payer@example.test',
        recipientLocale: 'de',
        templateKey: 'commerce.order-confirmation',
        payload: new OrderConfirmationMailData(
            orderNumberShort: 'ABCD1234',
            recipientName: $recipientName,
            placedAt: '28.08.2026 10:00',
            lines: [['label' => 'Con Shirt', 'quantity' => 1, 'lineGross' => '35.70']],
            totalGross: '35.70',
            currency: 'EUR',
            paid: false,
            reservationUntil: null,
            ordersUrl: 'https://brand.example.test/my/orders',
            orderUrl: 'https://brand.example.test/my/orders/01K5MZ6V4N8YQ2W3J7R9T1X0AB',
        ),
        channel: Channel::Mail->value,
    );

    return app(TemplateRenderer::class)->render($message)->body;
}

it('greets by name when one is known, and plainly when it is not', function (): void {
    $brand = $this->withBrand();

    expect(orderMailsConfirmationBody('Max Mustermann', $brand->id))
        ->toContain('<p>Hallo Max Mustermann,</p>');

    expect(orderMailsConfirmationBody(null, $brand->id))
        ->toContain('<p>Hallo,</p>');
});

it('honours the payer\'s own locale instead of hard-coding German', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id, 'en');
    Queue::fake();

    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'payer_user_id' => $payer->id,
    ]);
    OrderLine::factory()->forOrder($order)->create();

    event(new OrderPlaced(
        orderId: $order->id,
        brandId: $brand->id,
        settlesType: 'cart',
        settlesId: (string) Str::ulid(),
        totalNet: '30.00',
        totalGross: '35.70',
        currency: 'EUR',
        placedAt: now()->toIso8601String(),
    ));

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.order-confirmation',
    )->sole();

    expect($job->message->recipientLocale)->toBe('en')
        ->and(app(TemplateRenderer::class)->render($job->message)->subject)->toStartWith('Your order');
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
            // GitLab #5: a cart order retries on its own order-addressed pay
            // page, not on the order list — see `Settlement\PayLink`.
            && str_ends_with($payload['retryUrl'], '/pay/order/'.$order->id)
            && str_ends_with($payload['orderUrl'], '/my/orders/'.$order->id);
    });
});

it('honours the payer\'s own locale for the payment-failed mail instead of hard-coding German', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id, 'en');
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

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-failed',
    )->sole();

    expect($job->message->recipientLocale)->toBe('en')
        ->and(app(TemplateRenderer::class)->render($job->message)->subject)->toContain('Payment for order');
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

/**
 * #131, whole-branch review finding 2: the retry link invited a payment
 * `ExpireRegistrationOrders` was about to orphan, and the mail said nothing
 * about the deadline. `Settlement\PaymentWindow` is the sweep's own answer,
 * so the mail and the sweep cannot disagree.
 */
it('names the payment deadline in the payment-failed mail for a registration order (#131)', function (): void {
    $this->freezeTime();
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();
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

    $expected = $order->created_at->copy()->addMinutes(Cart::CHECKOUT_GRACE_MINUTES)->format('d.m.Y H:i');

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-failed'
        && $job->message->payload->toArray()['payBy'] === $expected);
});

it('names no deadline for a bank-transfer brand, which has none (#131)', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    Queue::fake();

    $order = Order::factory()->create(['settles_type' => 'registration', 'payer_user_id' => $payer->id]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'manual',
        reasonCode: 'canceled',
        failedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-failed'
        && $job->message->payload->toArray()['payBy'] === null);
});

/**
 * The pay page states the deadline in the viewer's own timezone; the mail
 * must not state a different clock time for the same instant by silently
 * rendering it in the application's UTC (review N4). `ResolveBrandTimezone`
 * is the same setting `Digests\HoldPolicy` already reads for quiet hours.
 */
it('names the payment deadline in the brand\'s own timezone, not the application\'s UTC (#131)', function (): void {
    $brand = $this->withBrand();
    $payer = orderMailsRecipient($brand->id);
    app(UpdateBrandMailSettings::class)->handle($brand->id, new MailSettingsData(
        fromName: 'Test Brand',
        fromAddress: 'no-reply@example.test',
        replyTo: null,
        timezone: 'Europe/Berlin',
    ));
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();
    Queue::fake();

    // 10:00 UTC is 11:00 CET (Europe/Berlin, UTC+1 in January) — the
    // deadline sixty minutes later is 12:00 Berlin, not 11:00.
    $this->travelTo(Carbon::parse('2026-01-15 10:00:00', 'UTC'));

    $order = Order::factory()->create(['settles_type' => 'registration', 'payer_user_id' => $payer->id]);

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->id,
        brandId: $brand->id,
        settlementMethod: 'mollie',
        reasonCode: 'canceled',
        failedAt: now()->toIso8601String(),
    ));

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-failed'
        && $job->message->payload->toArray()['payBy'] === '15.01.2026 12:00');
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

it('reaches exactly the permission holders with a stock-exhausted operations notice, with an absolute admin link', function (): void {
    $operator = Operator::factory()->create(['admin_host' => 'admin.orders-mails.test']);
    $brand = $this->withBrand(Brand::factory()->for($operator)->create());

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
    $adminBase = app(OperatorSettings::class)->adminBaseUrlForBrand($brand->id);

    expect($payload['kind'])->toBe('stock-exhausted')
        ->and($payload['title'])->toBe('Ausverkauft: Con Shirt – L')
        ->and($adminBase)->not->toBeNull()
        ->and($payload['url'])->toStartWith($adminBase)
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

it('links the operations notice absolutely even when the operator has no admin host', function (): void {
    // Task 9 of the 2026-09-21 bugfix round: a dev or single-host install
    // has no `admin_host`, and the bare `/admin/orders/{id}` the producers
    // used to store opened on whatever host the reader was on — a brand
    // host, which rejects the console path. `Operations\AdminUrl` falls
    // back to `config('app.url')` instead.
    $brand = $this->withBrand();
    orderMailsOperator($brand->id);
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

    $notice = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.operations-notice',
    )->first();

    $url = $notice->message->payload->toArray()['url'];

    expect(app(OperatorSettings::class)->adminBaseUrlForBrand($brand->id))->toBeNull()
        ->and($url)->toStartWith(rtrim((string) config('app.url'), '/').'/')
        ->and($url)->toEndWith('/admin/orders/'.$order->id);
});

it('honours an operator\'s own locale for the operations notice instead of hard-coding German', function (): void {
    $brand = $this->withBrand();
    $holder = orderMailsOperator($brand->id, locale: 'en');
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

    // `commerce.operations` is not `topicKey`-free — it fans out to both its
    // default channels, so two envelopes for this one holder are expected
    // (see the stock-exhausted case above); either carries the same locale.
    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.operations-notice'
            && $job->message->recipientId === $holder->id
            && $job->message->channel === Channel::Mail->value,
    )->sole();

    expect($job->message->recipientLocale)->toBe('en')
        ->and(app(TemplateRenderer::class)->render($job->message)->body)->toContain('Open in the console');
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
