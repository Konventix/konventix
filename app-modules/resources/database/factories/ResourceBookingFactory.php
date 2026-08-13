<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;

/**
 * @extends Factory<ResourceBooking>
 */
class ResourceBookingFactory extends Factory
{
    /** @var class-string<ResourceBooking> */
    protected $model = ResourceBooking::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'holder_type' => ResourceBooking::HOLDER_REGISTRATION,
            'holder_id' => (string) Str::ulid(),
            'registration_id' => null,
            'quantity' => 1,
            'is_exclusive' => true,
            'state' => BookingState::Confirmed,
            'starts_at' => now()->startOfHour(),
            'ends_at' => now()->startOfHour()->addHours(2),
            'hold_expires_at' => null,
        ];
    }
}
