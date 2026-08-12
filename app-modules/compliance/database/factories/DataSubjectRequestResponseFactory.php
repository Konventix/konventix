<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;

/**
 * @extends Factory<DataSubjectRequestResponse>
 */
class DataSubjectRequestResponseFactory extends Factory
{
    /** @var class-string<DataSubjectRequestResponse> */
    protected $model = DataSubjectRequestResponse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_subject_request_id' => DataSubjectRequest::factory(),
            // Deliberately not fake()->unique(): uniqueness only has to hold
            // per request (the table's real constraint), not globally, and a
            // global unique() here would exhaust Faker's pool after a
            // handful of factory calls in one test run.
            'module' => fake()->randomElement(['staffing', 'resources', 'commerce', 'registration']),
            'state' => ModuleResponseState::Pending,
            'responded_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'state' => ModuleResponseState::Completed,
            'responded_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'state' => ModuleResponseState::Failed,
            'responded_at' => now(),
        ]);
    }
}
