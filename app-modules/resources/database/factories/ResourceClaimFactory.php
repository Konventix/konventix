<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\ResourceClaim;

/**
 * @extends Factory<ResourceClaim>
 */
class ResourceClaimFactory extends Factory
{
    /** @var class-string<ResourceClaim> */
    protected $model = ResourceClaim::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it. The category
     * list is a fresh ULID because a claim only *names* categories; a test
     * that needs real ones chains `forResourceTypes()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'registration_id' => (string) Str::ulid(),
            'registrant_user_id' => (string) Str::ulid(),
            'amendment_id' => null,
            'resource_type_ids' => [(string) Str::ulid()],
            'seating_mode' => SeatingMode::Pick,
            'quantity' => 1,
            'state' => ClaimState::Unplaced,
        ];
    }

    public function forRegistration(string $registrationId): static
    {
        return $this->state(fn (array $attributes): array => ['registration_id' => $registrationId]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }

    public function forRegistrant(string $userId): static
    {
        return $this->state(fn (array $attributes): array => ['registrant_user_id' => $userId]);
    }

    public function forResourceTypes(string ...$resourceTypeIds): static
    {
        return $this->state(fn (array $attributes): array => ['resource_type_ids' => array_values($resourceTypeIds)]);
    }

    public function placed(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => ClaimState::Placed]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => ClaimState::Released]);
    }
}
