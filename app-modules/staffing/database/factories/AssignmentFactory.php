<?php

declare(strict_types=1);

namespace Konventix\Staffing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Models\Assignment;
use Konventix\Staffing\Models\ShiftRole;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /** @var class-string<Assignment> */
    protected $model = Assignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $role = ShiftRole::factory();

        return [
            'shift_role_id' => $role,
            'shift_id' => fn (array $attributes): string => ShiftRole::withoutBrandScope()
                ->whereKey($attributes['shift_role_id'])->firstOrFail()->shift_id,
            'user_id' => (string) Str::ulid(),
            'state' => AssignmentState::Accepted,
            'offered_at' => null,
            'responded_at' => now(),
        ];
    }

    public function offered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => AssignmentState::Offered,
            'offered_at' => now(),
            'responded_at' => null,
        ]);
    }
}
