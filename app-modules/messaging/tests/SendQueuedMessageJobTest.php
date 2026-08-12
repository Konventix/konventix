<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Spatie\LaravelData\Data;

/**
 * Covers TODO.md Next item 2 (per-brand transport resolution, fallback to the
 * default mailer) and the rendering half of item 3. The job is invoked
 * directly (`app()->call([$job, 'handle'])`) rather than through the real
 * queue, the same way Platform's own queue-propagation mechanism is tested
 * separately from the jobs that ride on it (see BrandContextQueueTest) — this
 * file is about MailChannel's behaviour once brand context is already
 * restored, not about the restoration itself.
 */
final class MessagingChannelTestPayload extends Data
{
    public function __construct(public readonly string $displayName) {}
}

function messagingChannelJob(string $brandId): SendQueuedMessage
{
    return new SendQueuedMessage(new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: $brandId,
        recipientId: (string) Str::ulid(),
        recipientEmail: 'ada@example.test',
        recipientLocale: 'en',
        templateKey: 'test.channel',
        payload: new MessagingChannelTestPayload('Ada'),
        channel: Channel::Mail->value,
    ));
}

beforeEach(function (): void {
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.channel',
        subjects: ['en' => 'Hello {{ $displayName }}'],
        bodies: ['en' => '<p>Welcome, {{ $displayName }}.</p>'],
    ));
});

it('sends through the brand\'s configured transport', function (): void {
    $brand = $this->withBrand();
    BrandMailSettings::factory()->withTransport(['transport' => 'array'])->create();
    Mail::fake();

    app()->call([messagingChannelJob($brand->getKey()), 'handle']);

    Mail::assertSent(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->mailer === 'messaging-brand-'.$brand->getKey()
            && $mail->hasTo('ada@example.test')
    );
});

it('falls back to the default mailer when the brand has no transport configured', function (): void {
    $brand = $this->withBrand();
    Mail::fake();

    app()->call([messagingChannelJob($brand->getKey()), 'handle']);

    Mail::assertSent(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->mailer === config('mail.default')
    );
});

it('uses the brand\'s sender identity when configured', function (): void {
    $brand = $this->withBrand();
    BrandMailSettings::factory()->create([
        'from_name' => 'Sund-Xplosion Events',
        'from_address' => 'events@sxlan.test',
        'reply_to' => 'support@sxlan.test',
    ]);
    Mail::fake();

    app()->call([messagingChannelJob($brand->getKey()), 'handle']);

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        $envelope = $mail->envelope();

        return $envelope->from?->address === 'events@sxlan.test'
            && $envelope->from?->name === 'Sund-Xplosion Events'
            && $envelope->replyTo[0]->address === 'support@sxlan.test';
    });
});

it('renders the subject and body from the registered template against the typed payload', function (): void {
    $brand = $this->withBrand();
    Mail::fake();

    app()->call([messagingChannelJob($brand->getKey()), 'handle']);

    Mail::assertSent(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->envelope()->subject === 'Hello Ada'
    );
});
