<?php

declare(strict_types=1);

use Konventix\Commerce\Data\StripeSetupData;
use Konventix\Commerce\Models\StripeSettings;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Stripe\StripeNotConfiguredException;
use Konventix\Platform\Models\Operator;

/**
 * ADR-0058 point 2: this instance holds exactly one Stripe account, and its
 * credentials are resolved in one place — the operator's own settings row
 * when it carries a secret key, otherwise the environment. Both stay
 * supported permanently, and neither value ever reaches a page prop.
 */
function stripeCredentialsRow(array $attributes = []): StripeSettings
{
    $operator = Operator::factory()->create();

    $settings = StripeSettings::forOperator((string) $operator->getKey());
    $settings->fill($attributes)->save();

    return $settings;
}

it('reads the environment when no settings row exists', function (): void {
    config()->set('services.stripe.secret', 'sk_test_environment_abcd');
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBe('sk_test_environment_abcd')
        ->and($credentials->webhookSecret())->toBe('whsec_environment')
        ->and($credentials->configured())->toBeTrue()
        ->and($credentials->source())->toBe('environment')
        ->and($credentials->mode())->toBe('test')
        ->and($credentials->lastFour())->toBe('abcd')
        ->and($credentials->publishableKeySet())->toBeFalse();
});

it('lets the operator settings row win over the environment', function (): void {
    config()->set('services.stripe.secret', 'sk_test_environment_abcd');
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    stripeCredentialsRow([
        'secret_key' => 'sk_live_console_wxyz',
        'webhook_secret' => 'whsec_console',
        'publishable_key' => 'pk_live_console',
    ]);

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBe('sk_live_console_wxyz')
        ->and($credentials->webhookSecret())->toBe('whsec_console')
        ->and($credentials->source())->toBe('database')
        ->and($credentials->mode())->toBe('live')
        ->and($credentials->lastFour())->toBe('wxyz')
        ->and($credentials->publishableKeySet())->toBeTrue();
});

it('falls back to the environment when the row carries no secret key', function (): void {
    config()->set('services.stripe.secret', 'sk_test_environment_abcd');
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    stripeCredentialsRow(['secret_key' => null, 'webhook_secret' => null]);

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBe('sk_test_environment_abcd')
        ->and($credentials->webhookSecret())->toBe('whsec_environment')
        ->and($credentials->source())->toBe('environment');
});

it('reports nothing configured when neither place carries a key', function (): void {
    config()->set('services.stripe.secret', '');
    config()->set('services.stripe.webhook_secret', '');

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBeNull()
        ->and($credentials->webhookSecret())->toBeNull()
        ->and($credentials->configured())->toBeFalse()
        ->and($credentials->source())->toBe('none')
        ->and($credentials->mode())->toBeNull()
        ->and($credentials->lastFour())->toBeNull();
});

it('stores the keys encrypted and never returns them to a page', function (): void {
    config()->set('services.stripe.secret', '');
    config()->set('services.stripe.webhook_secret', '');

    $settings = stripeCredentialsRow([
        'secret_key' => 'sk_live_console_wxyz',
        'webhook_secret' => 'whsec_console',
        'publishable_key' => 'pk_live_console',
    ]);

    // APP_KEY-encrypted at rest (ADR-0058 point 2): the raw column carries
    // no readable key, and no ciphertext is short enough to be the value.
    $raw = DB::table('stripe_settings')->where('id', $settings->id)->sole();

    expect($raw->secret_key)->not->toContain('sk_live_')
        ->and($raw->webhook_secret)->not->toContain('whsec_')
        ->and($raw->publishable_key)->not->toContain('pk_live_');

    $props = StripeSetupData::ofCredentials(app(StripeCredentials::class))->toArray();

    expect(json_encode($props))->not->toContain('sk_live_console_wxyz')
        ->and(json_encode($props))->not->toContain('whsec_console')
        ->and($props['source'])->toBe('database')
        ->and($props['mode'])->toBe('live')
        ->and($props['lastFour'])->toBe('wxyz')
        ->and($props['publishableKeySet'])->toBeTrue()
        ->and($props['configured'])->toBeTrue()
        ->and($props['webhookSecretSet'])->toBeTrue();
});

it('warns about a live key on a non-production instance', function (): void {
    config()->set('services.stripe.secret', 'sk_live_env_1234');
    config()->set('app.env', 'staging');

    expect(app(StripeCredentials::class)->liveKeyOffProduction())->toBeTrue();

    config()->set('app.env', 'production');

    expect(app(StripeCredentials::class)->liveKeyOffProduction())->toBeFalse();

    config()->set('app.env', 'staging');
    config()->set('services.stripe.secret', 'sk_test_env_1234');

    expect(app(StripeCredentials::class)->liveKeyOffProduction())->toBeFalse();
});

it('builds the Stripe client from the resolved key and names both places when there is none', function (): void {
    config()->set('services.stripe.secret', '');

    expect(fn () => app(StripeApi::class))->toThrow(
        StripeNotConfiguredException::class,
        'STRIPE_SECRET',
    );

    stripeCredentialsRow(['secret_key' => 'sk_test_console_1234', 'webhook_secret' => 'whsec_console']);

    // The row alone is enough — the environment stays empty.
    expect(app(StripeApi::class))->toBeInstanceOf(StripeApi::class);
});
