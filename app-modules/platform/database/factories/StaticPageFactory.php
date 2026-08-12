<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Platform\Models\StaticPage;

/**
 * @extends Factory<StaticPage>
 */
class StaticPageFactory extends Factory
{
    /** @var class-string<StaticPage> */
    protected $model = StaticPage::class;

    /**
     * Note there is no `brand_id` here on purpose: BelongsToBrand stamps it
     * from the resolved brand context, and a test that forgets to establish one
     * should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'blocks' => [],
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now(),
        ]);
    }
}
