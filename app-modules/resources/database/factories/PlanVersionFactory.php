<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\PlanVersion;

/**
 * @extends Factory<PlanVersion>
 */
class PlanVersionFactory extends Factory
{
    /** @var class-string<PlanVersion> */
    protected $model = PlanVersion::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'version' => 1,
            'document' => new PlanDocumentData,
            'source' => PlanVersionSource::Editor,
            'created_by' => null,
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan_id' => $plan->id,
            'version' => $plan->version,
            'document' => $plan->document,
        ]);
    }
}
