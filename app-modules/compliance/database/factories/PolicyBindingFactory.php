<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyBinding;

/**
 * @extends Factory<PolicyBinding>
 */
class PolicyBindingFactory extends Factory
{
    /** @var class-string<PolicyBinding> */
    protected $model = PolicyBinding::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context, and `operatorWide()` is how a caller asks for
     * the null (see Models\Concerns\MayBeOperatorWide).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'kind' => PolicyBindingKind::Event,
            'target_key' => (string) Str::ulid(),
            'created_by_user_id' => null,
        ];
    }

    public function ofKind(PolicyBindingKind $kind, string $targetKey): static
    {
        return $this->state(fn (): array => ['kind' => $kind, 'target_key' => $targetKey]);
    }

    public function toEvent(string $eventId): static
    {
        return $this->ofKind(PolicyBindingKind::Event, $eventId);
    }

    public function toRegistrationType(string $registrationTypeId): static
    {
        return $this->ofKind(PolicyBindingKind::RegistrationType, $registrationTypeId);
    }

    public function toEntryCondition(string $entryConditionId): static
    {
        return $this->ofKind(PolicyBindingKind::EntryCondition, $entryConditionId);
    }

    /**
     * The one kind whose `target_key` is not a ULID (D-CPL-02).
     */
    public function toSettlementProvider(string $provider): static
    {
        return $this->ofKind(PolicyBindingKind::SettlementProvider, $provider);
    }

    /**
     * The operator's own binding (#76, D-CPL-05): `brand_id` explicitly
     * null, which is how a caller asks for an operator-wide row.
     */
    public function operatorWide(): static
    {
        return $this->state(fn (): array => ['brand_id' => null]);
    }
}
