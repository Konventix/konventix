<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Events\RegistrationTypeEntryConditionsChanged;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\RegistrationType;

/**
 * Which declarations a type demands, and in which order (ADR-0066,
 * D-REG-18/19).
 *
 * The posted order *is* the order: `position` is the index in the submitted
 * list, so the public form renders the checkboxes the way the operator
 * arranged them.
 *
 * **Not a frozen axis** (D-REG-19). The set stays editable after the type
 * has sold, and nothing retroactive follows: no assertion is written for a
 * registration that already exists, and no historic assertion is removed
 * when a condition leaves the type. `RegistrationTypeEntryConditionsChanged`
 * carries the whole new set rather than a delta, so a consumer that missed
 * one still ends up right — and it is emitted only when the set actually
 * changed, because "saved with no change" is not news.
 *
 * A condition must belong to the type's own brand: the console creates types
 * for a brand it is not resolved into (ADR-0029), so the ownership check is
 * explicit rather than a scope's side effect.
 *
 * The alternative type pointer (D-REG-21) is guarded here too, for the same
 * reason and in the same breath: it is the other half of what a refused
 * checkbox needs.
 */
trait SyncsTypeEntryConditions
{
    /**
     * @param  list<string>  $entryConditionIds
     */
    private function syncEntryConditions(RegistrationType $type, array $entryConditionIds): void
    {
        $ids = array_values(array_unique($entryConditionIds));

        if ($ids !== []) {
            $known = EntryCondition::withoutBrandScope()
                ->where('brand_id', $type->brand_id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->all();

            if (count($known) !== count($ids)) {
                throw ValidationException::withMessages([
                    'entryConditionIds' => __('One of these entry conditions does not belong to this brand.'),
                ]);
            }
        }

        $current = $type->entryConditions()
            ->withoutGlobalScope(BrandScope::class)
            ->pluck('registration_entry_conditions.id')
            ->all();

        $type->entryConditions()->sync(
            array_combine($ids, array_map(
                static fn (int $position): array => ['position' => $position],
                array_keys($ids),
            ))
        );

        if ($current === $ids) {
            return;
        }

        $type->unsetRelation('entryConditions');

        event(new RegistrationTypeEntryConditionsChanged(
            registrationTypeId: (string) $type->getKey(),
            brandId: $type->brand_id,
            entryConditionIds: $ids,
        ));
    }

    /**
     * The alternative type is a pointer to a sibling offer of the same event
     * (D-REG-21) — never to itself, and never across events: "buy the
     * regular ticket instead" has to lead somewhere the person can actually
     * buy for the event they were looking at.
     */
    private function guardAlternativeType(RegistrationType $type, ?string $alternativeId, string $eventId): void
    {
        if ($alternativeId === null) {
            return;
        }

        if ($type->exists && $alternativeId === (string) $type->getKey()) {
            throw ValidationException::withMessages([
                'alternativeRegistrationTypeId' => __('A type cannot point at itself as the alternative.'),
            ]);
        }

        $sibling = RegistrationType::withoutBrandScope()
            ->whereKey($alternativeId)
            ->where('brand_id', $type->brand_id)
            ->where('event_id', $eventId)
            ->exists();

        if (! $sibling) {
            throw ValidationException::withMessages([
                'alternativeRegistrationTypeId' => __('The alternative type must belong to the same event.'),
            ]);
        }
    }
}
