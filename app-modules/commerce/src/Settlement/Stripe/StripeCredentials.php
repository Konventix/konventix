<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Konventix\Commerce\Models\StripeSettings;
use Konventix\Platform\Environment\DeploymentEnvironment;

/**
 * Where this instance's Stripe keys come from — the one place that answers
 * it (ADR-0058 point 2).
 *
 * The order is stated once, per value: **the operator settings row wins if
 * it carries a key; otherwise the environment value is used**
 * (`services.stripe.secret` / `services.stripe.webhook_secret`, which the
 * chart fills from the `secrets.payments` group). Both stay supported
 * permanently — the environment is what a fresh deployment sets before
 * anyone has logged in, the row is what an operator sets or rotates
 * without a redeploy. The `StripeApi` binding and `StripeWebhookController`
 * both read *this*, never `config()` directly, so the two cannot drift.
 *
 * Values leave this class only towards Stripe. Everything a console page is
 * allowed to know is derived here — `configured()`, `source()`, `mode()`,
 * `lastFour()`, `publishableKeySet()` — the "whether, never what" rule
 * `StripeSetupData` carries onto the page.
 *
 * Memoised for the length of one request, and no longer: bound with
 * `scoped()`, so the console page and every `SettlementAccount::isOffered()`
 * on it share one instance, and the row is read once instead of once per
 * accessor and once per Stripe-enabled brand. Octane calls
 * `forgetScopedInstances()` between requests, so a key rotated in the console
 * is live for the very next request — which is what the original "not
 * memoised" note was actually protecting. The *environment* half is
 * deliberately not memoised: `config()` is cheap and a test that flips it
 * mid-request should see the change.
 */
final class StripeCredentials
{
    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_ENVIRONMENT = 'environment';

    public const string SOURCE_NONE = 'none';

    private ?StripeSettings $settings = null;

    private bool $settingsLoaded = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DeploymentEnvironment $environment,
    ) {}

    /**
     * The instance's settings row, read at most once per request.
     */
    private function settings(): ?StripeSettings
    {
        if (! $this->settingsLoaded) {
            $this->settings = StripeSettings::forInstance();
            $this->settingsLoaded = true;
        }

        return $this->settings;
    }

    /**
     * The secret key every Stripe call is made with, or null when this
     * instance has none.
     */
    public function secret(): ?string
    {
        return $this->resolve(
            $this->settings()?->secret_key,
            'services.stripe.secret',
        );
    }

    /**
     * The signing secret the webhook is verified against — the same
     * row-before-environment order, so an operator who enters a pair in the
     * console does not end up verifying against a stale environment value.
     */
    public function webhookSecret(): ?string
    {
        return $this->resolve(
            $this->settings()?->webhook_secret,
            'services.stripe.webhook_secret',
        );
    }

    /**
     * Whether Stripe can be charged at all. This is what
     * `SettlementAccount::isOffered()` asks for a Stripe row: with no key,
     * offering the brand's Stripe button would fail at Stripe rather than
     * on our page.
     */
    public function configured(): bool
    {
        return $this->secret() !== null;
    }

    public function webhookSecretConfigured(): bool
    {
        return $this->webhookSecret() !== null;
    }

    /**
     * Where the secret key came from: `database` (the operator's row),
     * `environment`, or `none`.
     */
    public function source(): string
    {
        if (self::filled($this->settings()?->secret_key)) {
            return self::SOURCE_DATABASE;
        }

        return self::filled($this->config->get('services.stripe.secret'))
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_NONE;
    }

    /**
     * `live`, `test`, or null — read from Stripe's own key shape, which is
     * the only thing that decides whether real money moves.
     *
     * Matched on the `_live_` / `_test_` segment rather than on `sk_`: a
     * restricted key (`rk_live_…`) charges real money exactly like a secret
     * one, and a mode of null would silence the ADR-0053 warning for the one
     * key that most needs it.
     */
    public function mode(): ?string
    {
        $secret = $this->secret();

        return match (true) {
            $secret === null => null,
            str_contains($secret, '_live_') => 'live',
            str_contains($secret, '_test_') => 'test',
            default => null,
        };
    }

    /**
     * The last four characters of the secret key — enough for an operator
     * to tell which key is installed, useless to anyone who reads it
     * (ADR-0058 point 2).
     */
    public function lastFour(): ?string
    {
        $secret = $this->secret();

        return $secret === null ? null : substr($secret, -4);
    }

    public function publishableKeySet(): bool
    {
        return self::filled($this->settings()?->publishable_key);
    }

    /**
     * A live key on anything but production (ADR-0053): the instance is one
     * checkout away from moving real money against rehearsal data, so the
     * console says so loudly rather than leaving it to the key's prefix in
     * a status line.
     */
    public function liveKeyOffProduction(): bool
    {
        return $this->mode() === 'live' && ! $this->environment->isProduction();
    }

    private function resolve(?string $fromRow, string $configKey): ?string
    {
        if (self::filled($fromRow)) {
            return trim((string) $fromRow);
        }

        $fromConfig = $this->config->get($configKey);

        return self::filled($fromConfig) ? trim((string) $fromConfig) : null;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
