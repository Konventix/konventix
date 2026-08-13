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
}
