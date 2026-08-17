<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Models\RetentionClock;

/**
 * @extends Factory<RetentionClock>
 */
class RetentionClockFactory extends Factory
{
    /** @var class-string<RetentionClock> */
    protected $model = RetentionClock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => (string) Str::ulid(),
            'event_id' => (string) Str::ulid(),
            'concluded_at' => now()->subYear(),
        ];
    }
}
