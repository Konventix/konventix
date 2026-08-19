<?php

declare(strict_types=1);

namespace Konventix\Staffing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\ShiftRole;

/**
 * @extends Factory<ShiftRole>
 */
class ShiftRoleFactory extends Factory
{
    /** @var class-string<ShiftRole> */
    protected $model = ShiftRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            // The suffix is what makes the name unique, not `unique()` on the
            // list: `unique()` over four options runs out after four roles and
            // then throws, which is fewer than a single shift plan needs.
            'name' => fake()->randomElement(['Scanner', 'Runner', 'Lead', 'Support']).' '.fake()->unique()->numerify('####'),
            'admission_role' => 'crew',
            'required_headcount' => 1,
        ];
    }

    public function needing(int $headcount): static
    {
        return $this->state(fn (array $attributes): array => ['required_headcount' => $headcount]);
    }

    public function forShift(Shift $shift): static
    {
        return $this->state(fn (array $attributes): array => ['shift_id' => $shift->id]);
    }
}
