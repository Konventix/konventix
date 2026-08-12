<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /** @var class-string<Registration> */
    protected $model = Registration::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `registrant_user_id` is a plain ULID
     * (ADR-0014) rather than a real `User` row by default — D-REG-08 still
     * requires an account to exist for the real HTTP flow, but a unit-level
     * test building a `Registration` directly does not need Identity wired
     * up. Pass a real user's `id` explicitly when the test needs one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $registrationType = RegistrationType::factory()->create();

        return [
            'registration_type_id' => $registrationType->id,
            'event_id' => $registrationType->event_id,
            'registrant_user_id' => (string) Str::ulid(),
            'status' => RegistrationStatus::Submitted,
            'payment_status' => PaymentStatus::None,
            'organization_name' => null,
            'billing_recipient_name' => null,
            'billing_email' => null,
            'submitted_at' => now(),
        ];
    }

    public function forType(RegistrationType $registrationType): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_type_id' => $registrationType->id,
            'event_id' => $registrationType->event_id,
        ]);
    }

    public function forRegistrant(string $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'registrant_user_id' => $userId,
        ]);
    }

    /**
     * A complete billing party, as an invoice-settled submission provides it.
     * VAT ID and country stay null by default — pass them for the
     * reverse-charge cases (ADR-0030).
     */
    public function withBillingParty(?string $vatId = null, string $countryCode = 'DE'): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_name' => fake()->company(),
            'billing_recipient_name' => fake()->name(),
            'billing_email' => fake()->safeEmail(),
            'billing_address_line' => fake()->streetAddress(),
            'billing_postal_code' => fake()->postcode(),
            'billing_city' => fake()->city(),
            'billing_country_code' => $countryCode,
            'billing_vat_id' => $vatId,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Approved,
            'approved_at' => now(),
            'approved_by_user_id' => (string) Str::ulid(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by_user_id' => (string) Str::ulid(),
            'reason_code' => 'capacity',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => (string) Str::ulid(),
            'reason_code' => 'registrant-withdrew',
        ]);
    }
}
