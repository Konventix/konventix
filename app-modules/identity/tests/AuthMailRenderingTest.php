<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Identity\Data\PasswordResetMailData;
use Konventix\Identity\Data\VerifyEmailMailData;
use Konventix\Identity\Data\WelcomeMailData;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Messaging\Templates\TemplateRenderer;
use Laravel\Fortify\Features;
use Spatie\LaravelData\Data;

/**
 * ADR-0048 point 5: the three templates Identity registers
 * (`IdentityServiceProvider::registerMessageTemplates()`), rendered through
 * Messaging's own `TemplateRenderer` — the same instrument
 * `RegistrationMailsTest.php`'s `regMailsRender()` uses — in both registered
 * locales, plus one end-to-end send. `authMailRender*` helper names are
 * scoped to this file (.ai/rules/tests.md: Pest helper names are
 * process-global across every module's test files in one run).
 *
 * @return array{0: string, 1: string} subject, body
 */
function authMailRender(string $templateKey, Data $payload, string $locale): array
{
    $message = new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        recipientId: (string) Str::ulid(),
        recipientEmail: 'recipient@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: $payload,
        channel: Channel::Mail->value,
    );

    $rendered = app(TemplateRenderer::class)->render($message);

    return [$rendered->subject, $rendered->body];
}

it('renders identity.verify-email with the link and the validity, in both locales', function (): void {
    $payload = new VerifyEmailMailData(verifyUrl: 'https://brand.example.test/email/verify/01ABC/deadbeef?signature=abc123', validMinutes: 60);

    [$subjectDe, $bodyDe] = authMailRender('identity.verify-email', $payload, 'de');
    expect($subjectDe)->toBe('Bitte bestätigen Sie Ihre E-Mail-Adresse');
    expect($bodyDe)->toContain('href="'.$payload->verifyUrl.'"')
        ->and($bodyDe)->toContain('Minuten gültig');

    [$subjectEn, $bodyEn] = authMailRender('identity.verify-email', $payload, 'en');
    expect($subjectEn)->toBe('Please confirm your email address');
    expect($bodyEn)->toContain('href="'.$payload->verifyUrl.'"')
        ->and($bodyEn)->toContain('minutes');
});

it('renders identity.password-reset with the link and the validity, in both locales', function (): void {
    $payload = new PasswordResetMailData(resetUrl: 'https://brand.example.test/reset-password/plaintexttoken?email=ada%40example.test', validMinutes: 60);

    [$subjectDe, $bodyDe] = authMailRender('identity.password-reset', $payload, 'de');
    expect($subjectDe)->toBe('Passwort zurücksetzen');
    expect($bodyDe)->toContain('href="'.$payload->resetUrl.'"')
        ->and($bodyDe)->toContain('Minuten gültig');

    [$subjectEn, $bodyEn] = authMailRender('identity.password-reset', $payload, 'en');
    expect($subjectEn)->toBe('Reset password');
    expect($bodyEn)->toContain('href="'.$payload->resetUrl.'"')
        ->and($bodyEn)->toContain('minutes');
});

it('renders identity.welcome with the confirmation, the home link, and the shop paragraph when shopUrl is set, in both locales', function (): void {
    $payloadWithShop = new WelcomeMailData(brandName: 'Konventix Con', homeUrl: 'https://brand.example.test', shopUrl: 'https://brand.example.test/shop');

    [$subjectDe, $bodyDe] = authMailRender('identity.welcome', $payloadWithShop, 'de');
    expect($subjectDe)->toBe('Willkommen bei Konventix Con');
    expect($bodyDe)->toContain('Ihr Konto ist bestätigt')
        ->and($bodyDe)->toContain('href="https://brand.example.test"')
        ->and($bodyDe)->toContain('href="https://brand.example.test/shop"')
        ->and($bodyDe)->toContain('Zum Shop');

    [$subjectEn, $bodyEn] = authMailRender('identity.welcome', $payloadWithShop, 'en');
    expect($subjectEn)->toBe('Welcome to Konventix Con');
    expect($bodyEn)->toContain('Your account is confirmed')
        ->and($bodyEn)->toContain('href="https://brand.example.test"')
        ->and($bodyEn)->toContain('href="https://brand.example.test/shop"')
        ->and($bodyEn)->toContain('Go to the shop');
});

it('renders identity.welcome without a shop paragraph when shopUrl is null, in both locales', function (): void {
    $payloadNoShop = new WelcomeMailData(brandName: 'Konventix Con', homeUrl: 'https://brand.example.test', shopUrl: null);

    [, $bodyDe] = authMailRender('identity.welcome', $payloadNoShop, 'de');
    expect($bodyDe)->toContain('Ihr Konto ist bestätigt')
        ->and($bodyDe)->not->toContain('Zum Shop')
        ->and($bodyDe)->not->toContain('/shop');

    [, $bodyEn] = authMailRender('identity.welcome', $payloadNoShop, 'en');
    expect($bodyEn)->toContain('Your account is confirmed')
        ->and($bodyEn)->not->toContain('Go to the shop')
        ->and($bodyEn)->not->toContain('/shop');
});

it('sends an actual identity.verify-email mail end to end on registration', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withBrand();
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada-e2e@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    Mail::assertSent(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('ada-e2e@example.test')
            && str_contains($mail->envelope()->subject, 'bestätigen'),
    );
});
