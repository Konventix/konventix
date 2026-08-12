<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Platform\Models\AdminFavorite;

/**
 * @extends Factory<AdminFavorite>
 */
class AdminFavoriteFactory extends Factory
{
    /** @var class-string<AdminFavorite> */
    protected $model = AdminFavorite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::ulid(),
            'label' => fake()->words(2, true),
            'url' => '/admin/'.fake()->unique()->slug(),
        ];
    }
}
