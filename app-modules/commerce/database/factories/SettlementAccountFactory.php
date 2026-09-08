<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * @extends Factory<SettlementAccount>
 */
class SettlementAccountFactory extends Factory
{
    /** @var class-string<SettlementAccount> */
    protected $model = SettlementAccount::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => SettlementAccount::PROVIDER_MANUAL,
            'label' => 'Bank transfer',
            'provider_account_id' => null,
            'credentials' => null,
            'connected_at' => now(),
            'disconnected_at' => null,
        ];
    }

    public function provider(string $provider): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => $provider,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disconnected_at' => now(),
        ]);
    }

    /**
     * A live account the checkout page is willing to show (ADR-0046).
     * There is nothing provider-specific left to fake: a Stripe row is
     * offered once the instance holds a key at all (ADR-0058 point 3),
     * which is a config fact, not a row's.
     */
    public function offered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'connected_at' => now(),
        ]);
    }
}
