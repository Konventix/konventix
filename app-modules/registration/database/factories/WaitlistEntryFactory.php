<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /** @var class-string<WaitlistEntry> */
    protected $model = WaitlistEntry::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_type_id' => RegistrationType::factory(),
            'event_id' => (string) Str::ulid(),
            'registrant_user_id' => (string) Str::ulid(),
            'state' => WaitlistState::Queued,
            'queued_at' => now(),
        ];
    }

    public function forType(RegistrationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_type_id' => $type->id,
            'event_id' => $type->event_id,
        ]);
    }

    public function offered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => WaitlistState::Offered,
            'offered_at' => now(),
            'offer_expires_at' => now()->addHours(48),
        ]);
    }
}
