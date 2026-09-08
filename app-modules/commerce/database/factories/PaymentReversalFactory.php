<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\PaymentReversal;

/**
 * @extends Factory<PaymentReversal>
 */
class PaymentReversalFactory extends Factory
{
    /** @var class-string<PaymentReversal> */
    protected $model = PaymentReversal::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Str::ulid()->toBase32(),
            'order_id' => Str::ulid()->toBase32(),
            'settlement_account_id' => null,
            'provider' => 'stripe',
            'kind' => ReversalKind::Return,
            'status' => ReversalStatus::Final,
            'amount' => '119.00',
            'currency' => 'EUR',
            'provider_reference' => 'dp_'.Str::random(24),
            'reason_code' => 'insufficient_funds',
            'fee_amount' => null,
            'occurred_at' => now(),
            'finalized_at' => now(),
            'repay_due_on' => now()->addDays(7),
        ];
    }

    /**
     * A dispute the provider has not decided yet (ADR-0057 point 1): no
     * deadline, because nothing is owed until it is lost.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ReversalKind::Dispute,
            'status' => ReversalStatus::Open,
            'finalized_at' => null,
            'repay_due_on' => null,
        ]);
    }
}
