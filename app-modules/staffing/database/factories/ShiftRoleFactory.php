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
            'name' => fake()->unique()->randomElement(['Scanner', 'Runner', 'Lead', 'Support']).' '.fake()->randomNumber(3),
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
