<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\MediaItem;
use Spatie\LaravelData\Data;

/**
 * ADR-0048 point 4: every mail wears the branded layout Messaging owns, its
 * identity resolved per send from the envelope's own brand — never a
 * singleton holding one. Covers task-1-brief.md's "Tests first" list.
 */
final class BrandedLayoutTestPayload extends Data
{
    public function __construct(public readonly string $name) {}
}

function brandedLayoutTemplate(string $key = 'branded-layout.test'): void
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: $key,
        subjects: ['en' => 'Hello {{ $name }}'],
        bodies: ['en' => '<p>Welcome, {{ $name }}.</p><p>Second line.</p>'],
        defaultLocale: 'en',
    ));
}

function brandedLayoutRequest(string $templateKey = 'branded-layout.test', ?string $topicKey = null): SendMessageRequest
{
    return new SendMessageRequest(
        recipientId: null,
        templateKey: $templateKey,
        payload: new BrandedLayoutTestPayload('Ada'),
        recipientEmail: 'ada@example.test',
        recipientLocale: 'en',
        topicKey: $topicKey,
    );
}

it('renders the brand\'s logo, the accent colour, the seller line and the imprint link', function (): void {
    $brand = $this->withBrand();

    $logo = MediaItem::factory()->make(['alt_text' => 'Sund-Xplosion mark']);
    $logo->brand_id = $brand->getKey();
    $logo->save();
    $brand->forceFill(['logo_media_id' => $logo->getKey()])->save();

    FooterLink::factory()->create([
        'operator_id' => $brand->operator_id,
        'label' => 'Impressum',
        'url' => '/impressum',
    ]);

    brandedLayoutTemplate();
    Mail::fake();

    app(SendMessage::class)->handle(brandedLayoutRequest());

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($brand): bool {
        $html = $mail->render();

        expect($html)->toContain('<img')
            ->toContain('alt="Sund-Xplosion mark"')
            ->toContain($brand->themeTokens()['accent'] ?? 'hsl(0 0% 9%)')
            ->toContain($brand->operator->legal_name)
            ->toContain('/impressum')
            ->toContain('>Impressum<');

        return true;
    });
});

it('renders the brand name and the theme catalogue\'s default colours when the brand has no logo', function (): void {
    $brand = $this->withBrand();

    brandedLayoutTemplate();
    Mail::fake();

    app(SendMessage::class)->handle(brandedLayoutRequest());

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($brand): bool {
        $html = $mail->render();

        expect($html)->not->toContain('<img')
            ->toContain($brand->name)
            ->toContain('hsl(0 0% 9%)')
            ->toContain('hsl(0 0% 98%)');

        return true;
    });
});

it('renders a preferences link only for a template sent with a topic', function (): void {
    $this->withBrand();

    app(TopicRegistry::class)->register(new Topic(
        key: 'branded-layout.topic',
        label: 'Branded layout test topic',
        defaultChannels: [Channel::Mail],
    ));
    brandedLayoutTemplate();
    Mail::fake();

    app(SendMessage::class)->handle(brandedLayoutRequest(topicKey: 'branded-layout.topic'));

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        expect($mail->render())->toContain(route('notification-preferences.edit', absolute: true));

        return true;
    });
});

it('renders no preferences link for a template sent without a topic', function (): void {
    $this->withBrand();

    brandedLayoutTemplate();
    Mail::fake();

    app(SendMessage::class)->handle(brandedLayoutRequest());

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        expect($mail->render())->not->toContain(route('notification-preferences.edit', absolute: true));

        return true;
    });
});

it('renders the body inside the layout and carries the subject in a markup-free text part', function (): void {
    $this->withBrand();

    brandedLayoutTemplate();
    Mail::fake();

    app(SendMessage::class)->handle(brandedLayoutRequest());

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        $mail->assertSeeInHtml('Welcome, Ada.')
            ->assertSeeInText('Hello Ada')
            ->assertSeeInText('Welcome, Ada.')
            ->assertDontSeeInText('<p>');

        return true;
    });
});

it('dispatches the queued send only after the surrounding transaction commits', function (): void {
    $this->withBrand();

    brandedLayoutTemplate();
    Mail::fake();

    try {
        DB::transaction(function (): void {
            app(SendMessage::class)->handle(brandedLayoutRequest());

            throw new RuntimeException('rolling back on purpose');
        });
    } catch (RuntimeException) {
        // Expected: the transaction never commits.
    }

    Mail::assertNothingSent();
});
