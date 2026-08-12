<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Platform\Models\MediaItem;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    /** @var class-string<MediaItem> */
    protected $model = MediaItem::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. The path deliberately does not correspond to
     * a real file — tests exercising storage use `Storage::fake('media')`
     * and write what they need.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'media',
            'path' => 'factory/'.((string) Str::ulid()).'.png',
            'original_filename' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(10_000, 500_000),
            'alt_text' => null,
            'variants' => [],
            'uploaded_by_user_id' => null,
        ];
    }
}
