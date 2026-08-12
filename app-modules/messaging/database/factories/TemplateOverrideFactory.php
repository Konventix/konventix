<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Messaging\Models\TemplateOverride;

/**
 * @extends Factory<TemplateOverride>
 */
class TemplateOverrideFactory extends Factory
{
    /** @var class-string<TemplateOverride> */
    protected $model = TemplateOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_key' => fake()->slug(2),
            'locale' => 'en',
            'subject' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p>',
        ];
    }
}
