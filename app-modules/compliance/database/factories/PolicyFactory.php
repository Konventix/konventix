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

    public function terms(): static
    {
        return $this->state(fn (): array => ['slug' => 'terms', 'name' => 'Terms of Participation']);
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
