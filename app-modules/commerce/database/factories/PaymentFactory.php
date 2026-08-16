<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /** @var class-string<Payment> */
    protected $model = Payment::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Str::ulid()->toBase32(),
            'settlement_account_id' => null,
            'provider' => 'stripe',
            'status' => PaymentStatus::Pending,
            'amount' => '100.00',
            'currency' => 'EUR',
            'external_id' => 'cs_test_'.Str::random(24),
            'failure_reason' => null,
            'received_at' => null,
            'failed_at' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Succeeded,
            'received_at' => now(),
        ]);
    }

    public function failed(string $reason = 'declined'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);
    }
}
