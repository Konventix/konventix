<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    /** @var class-string<Consent> */
    protected $model = Consent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Not tied to a real users row on purpose — see the model
            // docblock and decision-log Round 5 D-REG-08.
            'subject_id' => (string) Str::ulid(),
            'policy_version_id' => PolicyVersion::factory()->published(),
            'granted_at' => now(),
            'capture_context' => 'registration_form',
            'withdrawn_at' => null,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => ['withdrawn_at' => now()]);
    }
}
