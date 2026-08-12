<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\RegistrationType;

/**
 * @extends Factory<InvitationToken>
 */
class InvitationTokenFactory extends Factory
{
    /** @var class-string<InvitationToken> */
    protected $model = InvitationToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_type_id' => RegistrationType::factory(),
            'token' => Str::random(48),
            'label' => null,
            'max_uses' => null,
            'uses' => 0,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function forType(RegistrationType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_type_id' => $type->id,
        ]);
    }

    public function singleUse(): static
    {
        return $this->state(fn (array $attributes): array => ['max_uses' => 1]);
    }
}
