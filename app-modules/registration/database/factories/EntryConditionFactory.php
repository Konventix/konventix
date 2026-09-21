<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Models\EntryCondition;

/**
 * @extends Factory<EntryCondition>
 */
class EntryConditionFactory extends Factory
{
    /** @var class-string<EntryCondition> */
    protected $model = EntryCondition::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context, and a test that forgot to establish one should
     * fail loudly rather than silently invent a brand.
     *
     * The key is unique per brand, so it is generated unique here — a test
     * that wants a stable one names it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Not `fake()->words()`: it is typed `array|string` whatever the
        // flag, and the unique key built below needs a plain string anyway
        // (the same trap `CompetitionTeamFactory` names).
        $name = 'Condition '.Str::upper(Str::random(6));

        return [
            'event_id' => null,
            'key' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => Str::title($name),
            'description' => null,
            'assertion_text' => 'I confirm that I meet this condition and will show proof of it at the door.',
            'document_hint' => null,
            'verification' => EntryConditionVerification::SelfDeclared,
            'archived_at' => null,
        ];
    }

    /**
     * The canonical example of the feature (spec §4.1).
     */
    public function student(): static
    {
        return $this->state(fn (): array => [
            'key' => 'student',
            'name' => 'Student ticket',
            'assertion_text' => 'I confirm that I am enrolled as a student and will present a valid student ID at the door.',
            'document_hint' => 'Valid student ID or enrolment certificate.',
            'verification' => EntryConditionVerification::DocumentAtDoor,
        ]);
    }

    public function verification(EntryConditionVerification $verification): static
    {
        return $this->state(fn (): array => ['verification' => $verification]);
    }

    /**
     * Pinned to one event (D-REG-17) — the one-off case, not the normal one.
     */
    public function forEvent(string $eventId): static
    {
        return $this->state(fn (): array => ['event_id' => $eventId]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
