<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /** @var class-string<Brand> */
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            // Fixed per brand (decision log Round 6, D-BATCH).
            'currency' => 'EUR',
            'theme_tokens' => [],
            // Also set by the model's `creating` hook; stated here so factory
            // use under WithoutModelEvents still yields broadcastable brands.
            'reverb_app_key' => Str::random(40),
            'reverb_app_secret' => Str::random(40),
        ];
    }

    /**
     * Give the brand a host, so it can be resolved by ResolveBrand.
     */
    public function withDomain(string $host): static
    {
        return $this->afterCreating(function (Brand $brand) use ($host): void {
            $brand->domains()->create(['host' => $host, 'is_primary' => true]);
        });
    }
}
