<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Compliance\Enums\RetentionClockStart;
use Konventix\Compliance\Models\RetentionRule;

/**
 * @extends Factory<RetentionRule>
 */
class RetentionRuleFactory extends Factory
{
    /** @var class-string<RetentionRule> */
    protected $model = RetentionRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => null,
            'data_category' => 'testing.category',
            'retention_months' => 12,
            'clock_start' => RetentionClockStart::RecordAge,
            'description' => null,
            'active' => true,
        ];
    }

    public function afterEvent(): static
    {
        return $this->state(fn (): array => ['clock_start' => RetentionClockStart::EventConcluded]);
    }
}
