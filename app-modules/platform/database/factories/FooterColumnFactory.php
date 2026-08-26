<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Models\FooterColumn;

/**
 * @extends Factory<FooterColumn>
 */
class FooterColumnFactory extends Factory
{
    /** @var class-string<FooterColumn> */
    protected $model = FooterColumn::class;

    /**
     * No `operator_id` here: footer columns are operator-owned and nothing
     * stamps one implicitly, so a caller states which operator's footer this
     * belongs to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'heading' => fake()->words(2, true),
            'blocks' => [['type' => 'text', 'data' => ['heading' => null, 'body' => fake()->sentence()]]],
            'position' => 0,
        ];
    }
}
