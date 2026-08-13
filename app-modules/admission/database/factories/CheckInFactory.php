<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    /** @var class-string<CheckIn> */
    protected $model = CheckIn::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_id' => Admission::factory(),
            'registration_id' => (string) Str::ulid(),
            'checkpoint_id' => null,
            'device_id' => null,
            'event_day_id' => null,
            'direction' => 'in',
            'result' => CheckInResult::Accepted,
            'reason_code' => null,
            'scanned_at' => now(),
            'synced_at' => now(),
            'client_scan_id' => (string) Str::uuid(),
        ];
    }

    public function onDay(?string $eventDayId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_day_id' => $eventDayId,
        ]);
    }

    public function rejected(string $reasonCode): static
    {
        return $this->state(fn (array $attributes): array => [
            'result' => CheckInResult::Rejected,
            'reason_code' => $reasonCode,
        ]);
    }
}
