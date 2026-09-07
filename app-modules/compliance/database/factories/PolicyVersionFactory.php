<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * @extends Factory<PolicyVersion>
 */
class PolicyVersionFactory extends Factory
{
    /** @var class-string<PolicyVersion> */
    protected $model = PolicyVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'version' => fake()->unique()->numerify('v#.#'),
            'content' => fake()->paragraphs(3, true),
            'requires_consent' => true,
            'state' => PolicyVersionState::Draft,
            'effective_at' => null,
            'published_at' => null,
        ];
    }

    /**
     * A version of an operator-wide policy: `brand_id` explicitly null, the
     * same signal the policy row itself carries (GitLab #76).
     */
    public function operatorWide(): static
    {
        return $this->state(fn (): array => ['brand_id' => null]);
    }

    /**
     * A version already in its published state, without going through
     * PublishPolicyVersion — useful for tests that need a published version
     * as a fixture rather than as the thing under test.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'state' => PolicyVersionState::Published,
            'effective_at' => now(),
            'published_at' => now(),
        ]);
    }
}
