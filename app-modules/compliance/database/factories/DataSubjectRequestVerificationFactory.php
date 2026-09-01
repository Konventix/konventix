<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequestVerification;

/**
 * @extends Factory<DataSubjectRequestVerification>
 */
class DataSubjectRequestVerificationFactory extends Factory
{
    /** @var class-string<DataSubjectRequestVerification> */
    protected $model = DataSubjectRequestVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = Str::random(64);

        return [
            'filed_on_brand_id' => null,
            'email' => $this->faker->unique()->safeEmail(),
            'type' => DataSubjectRequestType::Export,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(DataSubjectRequestVerification::TTL_HOURS),
        ];
    }
}
