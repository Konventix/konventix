<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;

/**
 * @extends Factory<EntryConditionAssertion>
 */
class EntryConditionAssertionFactory extends Factory
{
    /** @var class-string<EntryConditionAssertion> */
    protected $model = EntryConditionAssertion::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * The snapshots default to a wording of their own rather than reading the
     * condition's: a factory that copied the current text would hide exactly
     * the bug the snapshot exists to prevent (T-6). `assertedFor()` is the
     * state that copies it, because that is what submission does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'entry_condition_id' => EntryCondition::factory(),
            'subject_user_id' => (string) Str::ulid(),
            'asserted_at' => now(),
            'assertion_text_snapshot' => 'I confirm that I meet this condition and will show proof of it at the door.',
            'verification_snapshot' => 'self_declared',
            'state' => EntryConditionAssertionState::Asserted,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'decided_via' => null,
            'reason_code' => null,
        ];
    }

    /**
     * What submission writes: the condition's wording and verification as
     * they stood at the moment of the declaration.
     */
    public function assertedFor(EntryCondition $condition): static
    {
        return $this->state(fn (): array => [
            'entry_condition_id' => $condition->getKey(),
            'assertion_text_snapshot' => $condition->assertion_text,
            'verification_snapshot' => $condition->verification,
        ]);
    }

    public function decided(
        EntryConditionAssertionState $state,
        EntryConditionDecisionSource $via = EntryConditionDecisionSource::Console,
        ?EntryConditionReasonCode $reasonCode = null,
    ): static {
        return $this->state(fn (): array => [
            'state' => $state,
            'decided_by_user_id' => (string) Str::ulid(),
            'decided_at' => now(),
            'decided_via' => $via,
            'reason_code' => $reasonCode,
        ]);
    }
}
