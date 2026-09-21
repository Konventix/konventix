<?php

declare(strict_types=1);

namespace Konventix\Registration\Targets;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Platform\Data\TargetOptionData;
use Konventix\Platform\Targets\TargetProvider;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\RegistrationType;

/**
 * Registration's half of Platform's target registry (ADR-0066 §7.3,
 * D-CPL-11): the `registration_type` and `entry_condition` kinds.
 *
 * Both are ULID-keyed rows of this module, named for a console picker that
 * may not read them itself (ADR-0004). Nothing here learns what the picker
 * is for — the registry's kinds are generic, and a policy is never mentioned.
 *
 * Archived entry conditions are offered as options no longer (spec §4.2:
 * archiving is what takes a condition out of every picker) but are still
 * *labelled*, because a binding made while one was live must keep reading as
 * itself rather than as `Unknown (entry_condition · 01HX…)`.
 *
 * `withoutBrandScope()` throughout: the console asks from the operator admin
 * host, where `BrandScope` hardens every brand-owned query to zero rows
 * (ADR-0029, .ai/rules/app-modules-src.md); the brand filter is restated by
 * hand.
 */
final class RegistrationTargetProvider implements TargetProvider
{
    public const string KIND_TYPE = 'registration_type';

    public const string KIND_CONDITION = 'entry_condition';

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return [self::KIND_TYPE, self::KIND_CONDITION];
    }

    /**
     * @return list<TargetOptionData>
     */
    public function optionsFor(?string $brandId): array
    {
        $types = RegistrationType::withoutBrandScope()
            ->when($brandId !== null, fn (Builder $query): Builder => $query->where('brand_id', $brandId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (RegistrationType $type): TargetOptionData => new TargetOptionData(
                kind: self::KIND_TYPE,
                id: (string) $type->getKey(),
                label: $type->name,
                group: __('Registration types'),
            ));

        $conditions = EntryCondition::withoutBrandScope()
            ->whereNull('archived_at')
            ->when($brandId !== null, fn (Builder $query): Builder => $query->where('brand_id', $brandId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (EntryCondition $condition): TargetOptionData => new TargetOptionData(
                kind: self::KIND_CONDITION,
                id: (string) $condition->getKey(),
                label: $condition->name,
                group: __('Entry conditions'),
            ));

        return array_values($types->concat($conditions)->all());
    }

    public function labelFor(string $kind, string $id): ?string
    {
        /** @var string|null $name */
        $name = match ($kind) {
            self::KIND_TYPE => RegistrationType::withoutBrandScope()->whereKey($id)->value('name'),
            // Archived included, deliberately: see this class's docblock.
            self::KIND_CONDITION => EntryCondition::withoutBrandScope()->whereKey($id)->value('name'),
            default => null,
        };

        return $name;
    }
}
