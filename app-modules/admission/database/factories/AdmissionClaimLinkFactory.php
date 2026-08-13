<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;

/**
 * @extends Factory<AdmissionClaimLink>
 */
class AdmissionClaimLinkFactory extends Factory
{
    /** @var class-string<AdmissionClaimLink> */
    protected $model = AdmissionClaimLink::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_id' => Admission::factory(),
            'claimed_by_user_id' => null,
            'intended_email' => null,
            'expires_at' => now()->addHours(IssueAdmissionClaimLink::LIFETIME_HOURS),
            'claimed_at' => null,
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function claimed(string $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'claimed_by_user_id' => $userId,
            'claimed_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    public function boundTo(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'intended_email' => $email,
        ]);
    }
}
