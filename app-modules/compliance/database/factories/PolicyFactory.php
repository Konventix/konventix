<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Models\Policy;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    /** @var class-string<Policy> */
    protected $model = Policy::class;

    /**
     * Note there is no `brand_id` here on purpose: BelongsToBrand stamps it
     * from the resolved brand context, and a test that forgets to establish
     * one should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = rtrim(fake()->unique()->sentence(3), '.');

        return [
            'slug' => Str::slug($name),
            'name' => $name,
        ];
    }

    /**
     * The operator's own copy of a text (GitLab #76): `brand_id` explicitly
     * null, which is how a caller asks for an operator-wide row rather than
     * the resolved brand's (see Models\Concerns\MayBeOperatorWide).
     */
    public function operatorWide(): static
    {
        return $this->state(fn (): array => ['brand_id' => null]);
    }

    public function terms(): static
    {
        return $this->state(fn (): array => ['slug' => 'terms', 'name' => 'Terms of Participation']);
    }

    /**
     * The Widerrufsbelehrung (GitLab #76). Canonical slug `withdrawal`,
     * beside `terms` — the pair the public purchase paths ask for.
     */
    public function withdrawal(): static
    {
        return $this->state(fn (): array => ['slug' => 'withdrawal', 'name' => 'Withdrawal Information']);
    }

    public function privacy(): static
    {
        return $this->state(fn (): array => ['slug' => 'privacy', 'name' => 'Privacy Policy']);
    }

    public function houseRules(): static
    {
        return $this->state(fn (): array => ['slug' => 'house-rules', 'name' => 'House Rules']);
    }

    public function photoConsent(): static
    {
        return $this->state(fn (): array => ['slug' => 'photo-consent', 'name' => 'Photo Consent']);
    }
}
