<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;

/**
 * @extends Factory<BrandDomain>
 */
class BrandDomainFactory extends Factory
{
    /** @var class-string<BrandDomain> */
    protected $model = BrandDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'host' => fake()->unique()->domainName(),
            'is_primary' => false,
        ];
    }
}
