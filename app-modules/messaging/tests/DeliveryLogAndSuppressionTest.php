<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Konventix\Messaging\Actions\AddSuppression;
use Konventix\Messaging\Actions\LiftSuppression;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Events\RecipientSuppressionAdded;
use Konventix\Messaging\Events\RecipientSuppressionLifted;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Suppression;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\RenderedMessage;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;

/**
 * The delivery log and the suppression list (ADR-0041): every accepted
 * envelope leaves a row, refusals are visible facts, and bodies land only
 * on logBodies-flagged templates.
 */
final class DeliveryLogTestPayload extends Data
{
    public function __construct(public readonly string $name = 'Erika') {}
}

final class DeliveryLogTestFailingChannel implements MessageChannel
{
    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        throw new RuntimeException('transport exploded');
    }
}

function deliveryLogRegisterTemplate(bool $logBodies = false): void
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.delivery-log',
        subjects: ['en' => 'Hello {{ $name }}'],
        bodies: ['en' => '<p>Hi {{ $name }}</p>'],
        logBodies: $logBodies,
    ));
}

function deliveryLogSend(): array
{
    return app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'test.delivery-log',
        payload: new DeliveryLogTestPayload,
        recipientEmail: 'erika@example.test',
    ));
}

it('logs an accepted send as queued, then sent, and emits MessageDelivered', function (): void {
    $this->withBrand();
    deliveryLogRegisterTemplate();

    Mail::fake();
    Event::fake([MessageDelivered::class]);

    [$message] = deliveryLogSend();

    // QUEUE_CONNECTION=sync ran the job inline: the row is already sent.
    $record = MessageRecord::withoutBrandScope()->findOrFail($message->messageId);

    expect($record->status)->toBe(MessageStatus::Sent)
        ->and($record->attempts)->toBe(1)
        ->and($record->sent_at)->not->toBeNull()
        ->and($record->payload)->toBe(['name' => 'Erika'])
        // D-MSG-07: bodies only for flagged templates — this one is not.
        ->and($record->rendered_subject)->toBeNull()
        ->and($record->rendered_body)->toBeNull();

    Event::assertDispatched(
        MessageDelivered::class,
        fn (MessageDelivered $e): bool => $e->messageId === $message->messageId && $e->channel === 'mail',
    );
});

it('stores the rendered body only for logBodies-flagged templates', function (): void {
    $this->withBrand();
    deliveryLogRegisterTemplate(logBodies: true);

    Mail::fake();

    [$message] = deliveryLogSend();

    $record = MessageRecord::withoutBrandScope()->findOrFail($message->messageId);

    expect($record->rendered_subject)->toBe('Hello Erika')
        ->and($record->rendered_body)->toBe('<p>Hi Erika</p>');
});

it('refuses a suppressed address before dispatch, loudly', function (): void {
    $brand = $this->withBrand();
    deliveryLogRegisterTemplate();

    Suppression::factory()->create([
        'brand_id' => $brand->id,
        'channel' => Channel::Mail,
        'email' => 'erika@example.test',
    ]);

    Mail::fake();
    Event::fake([MessageSuppressed::class]);

    $envelopes = deliveryLogSend();

    expect($envelopes)->toBe([]);

    $record = MessageRecord::withoutBrandScope()->sole();
    expect($record->status)->toBe(MessageStatus::Suppressed);

    Event::assertDispatched(
        MessageSuppressed::class,
        fn (MessageSuppressed $e): bool => $e->reasonCode === 'suppression_list',
    );
    Mail::assertNothingSent();
});

it('does not block on a lifted suppression or another brand\'s', function (): void {
    $brand = $this->withBrand();
    deliveryLogRegisterTemplate();

    Suppression::factory()->lifted()->create([
        'brand_id' => $brand->id,
        'channel' => Channel::Mail,
        'email' => 'erika@example.test',
    ]);
    // Another brand's live suppression says nothing about this one
    // (ADR-0028: reputation is per sending domain).
    Suppression::factory()->create([
        'brand_id' => Brand::factory()->create()->id,
        'channel' => Channel::Mail,
        'email' => 'erika@example.test',
    ]);

    Mail::fake();

    [$message] = deliveryLogSend();

    expect(MessageRecord::withoutBrandScope()->findOrFail($message->messageId)->status)
        ->toBe(MessageStatus::Sent);
});

it('records attempts and failure when the channel throws', function (): void {
    $this->withBrand();
    deliveryLogRegisterTemplate();

    $map = new ChannelMap;
    $map->register(Channel::Mail, DeliveryLogTestFailingChannel::class);
    app()->instance(ChannelMap::class, $map);

    Event::fake([MessageFailed::class, MessageDelivered::class]);

    expect(fn () => deliveryLogSend())->toThrow(RuntimeException::class, 'transport exploded');

    $record = MessageRecord::withoutBrandScope()->sole();

    // The sync driver runs failed() after the single inline attempt.
    expect($record->status)->toBe(MessageStatus::Failed)
        ->and($record->last_error)->toBe('transport exploded')
        ->and($record->attempts)->toBe(1);

    Event::assertDispatched(
        MessageFailed::class,
        fn (MessageFailed $e): bool => $e->attempt === 1 && $e->failureCode === RuntimeException::class,
    );
    Event::assertNotDispatched(MessageDelivered::class);
});

it('adds and lifts suppressions idempotently, with contract events', function (): void {
    $brand = $this->withBrand();

    Event::fake([RecipientSuppressionAdded::class, RecipientSuppressionLifted::class]);

    $first = app(AddSuppression::class)->handle($brand->id, Channel::Mail, 'x@example.test', null, 'hard_bounce');
    $second = app(AddSuppression::class)->handle($brand->id, Channel::Mail, 'x@example.test', null, 'complaint');

    expect($second->id)->toBe($first->id);
    Event::assertDispatchedTimes(RecipientSuppressionAdded::class, 1);

    app(LiftSuppression::class)->handle($first);
    app(LiftSuppression::class)->handle($first->refresh());

    expect($first->refresh()->lifted_at)->not->toBeNull();
    Event::assertDispatchedTimes(RecipientSuppressionLifted::class, 1);
});

it('refuses a suppression bound to both or neither key', function (): void {
    $brand = $this->withBrand();

    expect(fn () => app(AddSuppression::class)->handle($brand->id, Channel::Mail, null, null, 'x'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AddSuppression::class)->handle($brand->id, Channel::Mail, 'a@b.test', 'some-ulid', 'x'))
        ->toThrow(InvalidArgumentException::class);
});
