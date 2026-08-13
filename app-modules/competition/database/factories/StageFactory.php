<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Stage;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    /** @var class-string<Stage> */
    protected $model = Stage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'name' => 'Playoffs',
            'format' => 'single_elimination',
            'sequence' => 1,
            'settings' => null,
            'generated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function format(string $format, array $settings = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'format' => $format,
            'settings' => $settings === [] ? null : $settings,
        ]);
    }
}
