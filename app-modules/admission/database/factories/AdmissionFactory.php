<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;

/**
 * @extends Factory<Admission>
 */
class AdmissionFactory extends Factory
{
    /** @var class-string<Admission> */
    protected $model = Admission::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `registration_id`/`event_id` are plain ULIDs
     * (ADR-0014) — Registration and Event are different modules, so this
     * deliberately does not create rows there.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => (string) Str::ulid(),
            'event_id' => (string) Str::ulid(),
            'holder_user_id' => null,
            'role' => 'visitor',
            'valid_from' => null,
            'valid_until' => null,
            'state' => AdmissionState::Issued,
        ];
    }

    public function forRegistration(string $registrationId): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_id' => $registrationId,
        ]);
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }

    public function assigned(?string $holderUserId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'holder_user_id' => $holderUserId ?? (string) Str::ulid(),
            'state' => AdmissionState::Assigned,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AdmissionState::Revoked,
        ]);
    }

    public function validBetween(DateTimeInterface $from, DateTimeInterface $until): static
    {
        return $this->state(fn (array $attributes): array => [
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    }
}
