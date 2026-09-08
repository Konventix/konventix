<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    // Through the factory, so the `newFactory()`/`$model` wiring every module
    // model needs (.ai/rules/models.md) is actually exercised — the failure
    // that rule exists to catch shows up here or nowhere.
    return StripeSettings::factory()->create([
        'operator_id' => (string) $operator->getKey(),
        ...$attributes,
    ]);
}

it('hands back a defaulted unsaved row for an operator that has never saved one', function (): void {
    // ADR-0058 point 2: the console form works before anybody has saved
    // anything, the same way `BookkeepingSettings` does.
    $operator = Operator::factory()->create();

    $fresh = StripeSettings::forOperator((string) $operator->getKey());

    expect($fresh->exists)->toBeFalse()
        ->and($fresh->operator_id)->toBe((string) $operator->getKey())
        ->and($fresh->secret_key)->toBeNull();

    $saved = StripeSettings::factory()->create([
        'operator_id' => (string) $operator->getKey(),
        'secret_key' => 'sk_test_saved_1234',
    ]);

    expect(StripeSettings::forOperator((string) $operator->getKey())->getKey())->toBe($saved->getKey());
});

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

it('decides per value, so a row with only a secret key still uses the environment\'s signing secret', function (): void {
    // The order is stated per value, not once for the pair: an operator who
    // pastes only the secret key into the console keeps verifying webhooks
    // against `STRIPE_WEBHOOK_SECRET`. Getting this wrong in either direction
    // is a silent 400 on every event.
    config()->set('services.stripe.secret', 'sk_test_environment_abcd');
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    stripeCredentialsRow(['secret_key' => 'sk_test_console_wxyz', 'webhook_secret' => null]);

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBe('sk_test_console_wxyz')
        ->and($credentials->webhookSecret())->toBe('whsec_environment')
        ->and($credentials->source())->toBe('database')
        ->and($credentials->webhookSecretConfigured())->toBeTrue();
});

it('is not chargeable while the signing secret is missing, whichever place holds the key', function (): void {
    // ADR-0039 point 2: a webhook is the only source of settlement truth, so a
    // Checkout Session created against a key with no verifiable signing secret
    // would take the payer's money and never settle the order. "Configured"
    // therefore means the pair, not the key.
    config()->set('services.stripe.secret', 'sk_test_environment_abcd');
    config()->set('services.stripe.webhook_secret', '');

    $credentials = app(StripeCredentials::class);

    expect($credentials->secret())->toBe('sk_test_environment_abcd')
        ->and($credentials->secretKeyConfigured())->toBeTrue()
        ->and($credentials->webhookSecretConfigured())->toBeFalse()
        ->and($credentials->configured())->toBeFalse();

    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    expect(app(StripeCredentials::class)->configured())->toBeTrue();
});

it('is not chargeable while the secret key is missing either', function (): void {
    config()->set('services.stripe.secret', '');
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    $credentials = app(StripeCredentials::class);

    expect($credentials->secretKeyConfigured())->toBeFalse()
        ->and($credentials->webhookSecretConfigured())->toBeTrue()
        ->and($credentials->configured())->toBeFalse();
});

it('reads a restricted key as the key it is', function (string $secret, string $mode): void {
    // `stripe login` mints restricted keys, and an operator pasting one gets
    // exactly the same account: what a restricted key may do is scoped at
    // Stripe, and a missing scope fails at the call with Stripe's own message.
    config()->set('services.stripe.secret', $secret);
    config()->set('services.stripe.webhook_secret', 'whsec_environment');

    $credentials = app(StripeCredentials::class);

    expect($credentials->mode())->toBe($mode)
        ->and($credentials->configured())->toBeTrue()
        ->and($credentials->lastFour())->toBe(substr($secret, -4));
})->with([
    'a restricted sandbox key' => ['rk_test_restricted_1234', 'test'],
    'a restricted live key' => ['rk_live_restricted_wxyz', 'live'],
    'a standard sandbox key' => ['sk_test_standard_1234', 'test'],
    'a standard live key' => ['sk_live_standard_wxyz', 'live'],
]);

it('reads no mode at all from something that is not a secret key', function (): void {
    // A publishable key carries `_live_` too; only the `sk_`/`rk_` prefixes
    // name a key that can charge, so a `pk_` in the environment is no mode
    // rather than a false "live".
    config()->set('services.stripe.secret', 'pk_live_publishable_1234');

    expect(app(StripeCredentials::class)->mode())->toBeNull();
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

it('keeps the keys out of any serialisation of the model', function (): void {
    // Third leg of the same theme as the session flash and Telescope: the
    // casts decrypt on read, so a `toArray()` in a log context or an exception
    // dump would hand over all three in cleartext. `Brand.reverb_app_secret`
    // is the house precedent.
    $settings = stripeCredentialsRow([
        'secret_key' => 'sk_live_console_wxyz',
        'webhook_secret' => 'whsec_console',
        'publishable_key' => 'pk_live_console',
    ]);

    expect(json_encode($settings->toArray()))->not->toContain('sk_live_console_wxyz')
        ->and(json_encode($settings->toArray()))->not->toContain('whsec_console')
        ->and(json_encode($settings->toArray()))->not->toContain('pk_live_console')
        ->and($settings->toArray())->not->toHaveKeys(['secret_key', 'webhook_secret', 'publishable_key'])
        // Hidden from serialisation, still readable through the attribute —
        // that is the whole point of `$hidden` rather than a dropped cast.
        ->and($settings->secret_key)->toBe('sk_live_console_wxyz');
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

it('reads the settings row once however many questions it is asked', function (): void {
    // Every accessor used to re-query, and `StripeSetupData::ofCredentials()`
    // asks seven of them in one expression while `isOffered()` asks once per
    // Stripe-enabled brand — eight queries plus one per brand for a value that
    // cannot change inside a request. The memo is request-scoped, so a rotation
    // is still picked up by the next request (and, under Octane, after
    // `forgetScopedInstances()`).
    stripeCredentialsRow(['secret_key' => 'sk_test_console_1234', 'webhook_secret' => 'whsec_console']);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'stripe_settings')) {
            $queries++;
        }
    });

    $credentials = app(StripeCredentials::class);

    $credentials->secret();
    $credentials->webhookSecret();
    $credentials->configured();
    $credentials->webhookSecretConfigured();
    $credentials->source();
    $credentials->mode();
    $credentials->lastFour();
    $credentials->publishableKeySet();
    $credentials->liveKeyOffProduction();

    // Scoped, so the console page and every `isOffered()` on it share one
    // instance rather than resolving a fresh resolver per account row.
    expect(app(StripeCredentials::class))->toBe($credentials)
        ->and($queries)->toBe(1);
});

it('resolves deterministically and says so when a second settings row exists', function (): void {
    // ADR-0001 gives an instance one operator and the column is unique per
    // operator, so this cannot happen — but nothing in the schema enforces it,
    // and the two console writers key on the resolved operator while this reads
    // instance-wide. If they ever disagree, the log says so instead of the
    // webhook quietly verifying against a key nobody can see.
    config()->set('services.stripe.secret', '');

    $this->travelTo(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
    stripeCredentialsRow(['secret_key' => 'sk_test_oldest_1111']);

    $this->travelTo(Carbon::parse('2026-09-08 11:00:00', 'UTC'));
    stripeCredentialsRow(['secret_key' => 'sk_test_newer_2222']);

    Log::spy();

    expect(app(StripeCredentials::class)->secret())->toBe('sk_test_oldest_1111');

    Log::shouldHaveReceived('warning')->once();
});

it('sees a live restricted key for what it is', function (): void {
    // A restricted key (`rk_live_…`) charges real money exactly like a secret
    // one, so the ADR-0053 warning has to fire for it — the mode is the
    // `_live_` segment, not the `sk_` prefix.
    config()->set('services.stripe.secret', 'rk_live_restricted_abcd');
    config()->set('app.env', 'staging');

    $credentials = app(StripeCredentials::class);

    expect($credentials->mode())->toBe('live')
        ->and($credentials->liveKeyOffProduction())->toBeTrue()
        ->and($credentials->lastFour())->toBe('abcd');
});

it('names both places when this instance has no Stripe key at all', function (): void {
    config()->set('services.stripe.secret', '');

    expect(fn () => app(StripeApi::class))->toThrow(
        StripeNotConfiguredException::class,
        'STRIPE_SECRET',
    );
});

it('builds the Stripe client from the operator settings row alone', function (): void {
    // The environment stays empty: the row is the whole answer. Its own test
    // rather than a second half of the one above, because the resolver is
    // memoised per request — a key entered in the console is live for the next
    // request, which is what a fresh test container stands for.
    config()->set('services.stripe.secret', '');

    stripeCredentialsRow(['secret_key' => 'sk_test_console_1234', 'webhook_secret' => 'whsec_console']);

    expect(app(StripeApi::class))->toBeInstanceOf(StripeApi::class);
});
