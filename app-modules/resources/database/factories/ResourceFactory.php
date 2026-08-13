<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceType;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    /** @var class-string<resource> */
    protected $model = Resource::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_type_id' => ResourceType::factory(),
            'venue_id' => null,
            'label' => fake()->bothify('##-?'),
            'capacity' => 1,
            'is_published' => true,
        ];
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (array $attributes): array => ['capacity' => $capacity]);
    }

    public function ofType(ResourceType $type): static
    {
        return $this->state(fn (array $attributes): array => ['resource_type_id' => $type->id]);
    }
}
