<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Models\BrandStorageSetting;

/**
 * @extends Factory<BrandStorageSetting>
 */
class BrandStorageSettingFactory extends Factory
{
    /** @var class-string<BrandStorageSetting> */
    protected $model = BrandStorageSetting::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bucket' => 'brand-'.fake()->word(),
            'region' => 'garage',
            'endpoint' => 'http://garage:3900',
            'prefix' => 'media',
            'use_path_style_endpoint' => true,
            'public_direct' => false,
            'url' => null,
            'credentials' => ['key' => 'GKtest', 'secret' => 'secret'],
        ];
    }

    public function publicDirect(string $url): static
    {
        return $this->state(fn (array $attributes): array => [
            'public_direct' => true,
            'url' => $url,
        ]);
    }
}
