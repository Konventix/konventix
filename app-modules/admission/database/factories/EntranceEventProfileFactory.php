<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\EntranceEventProfile;

/**
 * @extends Factory<EntranceEventProfile>
 */
class EntranceEventProfileFactory extends Factory
{
    /** @var class-string<EntranceEventProfile> */
    protected $model = EntranceEventProfile::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'reentry_policy' => ReentryPolicy::Unlimited,
            'days' => [
                ['day_id' => (string) Str::ulid(), 'date' => now()->format('Y-m-d'), 'sequence' => 1, 'label' => 'Day 1'],
            ],
        ];
    }

    public function policy(ReentryPolicy $policy): static
    {
        return $this->state(fn (array $attributes): array => [
            'reentry_policy' => $policy,
        ]);
    }

    /**
     * @param  list<array{day_id: string, date: string, sequence: int, label: string|null}>  $days
     */
    public function withDays(array $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'days' => $days,
        ]);
    }
}
