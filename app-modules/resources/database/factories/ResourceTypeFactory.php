<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Models\ResourceType;

/**
 * @extends Factory<ResourceType>
 */
class ResourceTypeFactory extends Factory
{
    /** @var class-string<ResourceType> */
    protected $model = ResourceType::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Seat', 'Booth', 'Meeting room', 'Locker']);

        return [
            'event_id' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'mode' => CapacityMode::Unit,
            'hold_minutes' => 30,
        ];
    }

    public function quantityMode(): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => CapacityMode::Quantity]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }
}
