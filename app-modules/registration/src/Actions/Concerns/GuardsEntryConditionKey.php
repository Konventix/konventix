<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Registration\Models\EntryCondition;

/**
 * One brand, one meaning per key (spec §4.1).
 *
 * The key — not the ULID — is what rides on an admission to the door and
 * prints on the ticket PDF, so two conditions of one brand sharing one would
 * make the door's label ambiguous years after anybody remembers why. The
 * unique index is the real guarantee; this is the refusal an operator can
 * act on, on the field that caused it.
 *
 * Archived conditions count: their key is still on past admissions.
 */
trait GuardsEntryConditionKey
{
    private function guardKeyIsFree(string $brandId, string $key, ?string $exceptId = null): void
    {
        $taken = EntryCondition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('key', $key)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'key' => __('This brand already has an entry condition with the key :key.', ['key' => $key]),
            ]);
        }
    }
}
