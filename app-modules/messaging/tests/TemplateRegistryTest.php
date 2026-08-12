<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;

/**
 * Covers TODO.md Next item 3: templates registered by key at boot, per-brand
 * overrides, and localisation by recipient locale, rendered against a typed
 * payload rather than a free-form array.
 */
final class MessagingTemplateTestPayload extends Data
{
    public function __construct(public readonly string $displayName) {}
}

function messagingQueuedMessage(string $templateKey, string $locale, string $brandId): SendMessageData
{
    return new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: $brandId,
        recipientId: (string) Str::ulid(),
        recipientEmail: 'ada@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: new MessagingTemplateTestPayload('Ada'),
        channel: Channel::Mail->value,
    );
}

it('renders a registered template against the typed payload', function (): void {
    $brand = $this->withBrand();

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.registered',
        subjects: ['en' => 'Hello {{ $displayName }}'],
        bodies: ['en' => '<p>Welcome, {{ $displayName }}.</p>'],
    ));

    $rendered = app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.registered', 'en', $brand->getKey())
    );

    expect($rendered->subject)->toBe('Hello Ada')
        ->and($rendered->body)->toBe('<p>Welcome, Ada.</p>')
        ->and($rendered->locale)->toBe('en');
});

it('throws for a template key nothing has registered', function (): void {
    $brand = $this->withBrand();

    app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.unregistered', 'en', $brand->getKey())
    );
})->throws(InvalidArgumentException::class);

it('selects the recipient\'s locale when the template defines it', function (): void {
    $brand = $this->withBrand();

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.locale',
        subjects: ['en' => 'Hello {{ $displayName }}', 'de' => 'Hallo {{ $displayName }}'],
        bodies: ['en' => 'Welcome, {{ $displayName }}.', 'de' => 'Willkommen, {{ $displayName }}.'],
    ));

    $rendered = app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.locale', 'de', $brand->getKey())
    );

    expect($rendered->locale)->toBe('de')
        ->and($rendered->subject)->toBe('Hallo Ada');
});

it('falls back to the template\'s default locale when the recipient\'s locale is not defined', function (): void {
    $brand = $this->withBrand();

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.fallback-locale',
        subjects: ['en' => 'Hello {{ $displayName }}'],
        bodies: ['en' => 'Welcome, {{ $displayName }}.'],
        defaultLocale: 'en',
    ));

    $rendered = app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.fallback-locale', 'fr', $brand->getKey())
    );

    expect($rendered->locale)->toBe('en')
        ->and($rendered->subject)->toBe('Hello Ada');
});

it('renders a brand\'s override instead of the registered default', function (): void {
    $brand = $this->withBrand();

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.override',
        subjects: ['en' => 'Default subject'],
        bodies: ['en' => 'Default body for {{ $displayName }}.'],
    ));

    TemplateOverride::factory()->create([
        'template_key' => 'test.override',
        'locale' => 'en',
        'subject' => 'Brand subject for {{ $displayName }}',
        'body' => null,
    ]);

    $rendered = app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.override', 'en', $brand->getKey())
    );

    // The override replaces only the field it sets; a null override field
    // falls back to the registered default.
    expect($rendered->subject)->toBe('Brand subject for Ada')
        ->and($rendered->body)->toBe('Default body for Ada.');
});

it('does not apply another brand\'s override', function (): void {
    $brandA = $this->withBrand();

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.scoped-override',
        subjects: ['en' => 'Default subject'],
        bodies: ['en' => 'Default body.'],
    ));

    $brandB = Brand::factory()->create();
    $this->withBrand($brandB);
    TemplateOverride::factory()->create([
        'template_key' => 'test.scoped-override',
        'locale' => 'en',
        'subject' => 'Brand B subject',
    ]);

    $this->withBrand($brandA);

    $rendered = app(TemplateRenderer::class)->render(
        messagingQueuedMessage('test.scoped-override', 'en', $brandA->getKey())
    );

    expect($rendered->subject)->toBe('Default subject');
});
