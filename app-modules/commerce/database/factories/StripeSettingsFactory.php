<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Models\StripeSettings;

/**
 * @extends Factory<StripeSettings>
 */
class StripeSettingsFactory extends Factory
{
    /** @var class-string<StripeSettings> */
    protected $model = StripeSettings::class;

    /**
     * A sandbox pair by default — a factory must never mint something that
     * looks like a live key (ADR-0053).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => Str::ulid()->toBase32(),
            'secret_key' => 'sk_test_factory_0001',
            'webhook_secret' => 'whsec_factory',
            'publishable_key' => null,
        ];
    }
}
