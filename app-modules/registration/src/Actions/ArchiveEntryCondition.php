<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Events\EntryConditionArchived;
use Konventix\Registration\Models\EntryCondition;

/**
 * Takes a condition out of circulation (spec §4.2, §9.1): it disappears from
 * the type editor's picker and stays readable on every registration that
 * ever asserted it.
 *
 * **Archive, never delete.** The assertion FK restricts, so a delete would
 * either fail or take the evidence with it — and "this person declared they
 * were a student" is exactly the record a refund dispute or a GDPR request
 * asks for.
 *
 * Archiving deliberately does **not** detach the condition from the types
 * that still demand it: which conditions a type asks for is the type
 * editor's decision, taken per type and with the warning that card shows
 * (§4.2), not a side effect of tidying a catalogue. A type still holding an
 * archived condition keeps asking for it, and the editor's picker keeps
 * offering that one row so saving the type cannot drop it by accident.
 *
 * Idempotent: archiving an archived condition changes nothing and emits
 * nothing.
 */
final class ArchiveEntryCondition
{
    public function handle(EntryCondition $condition): EntryCondition
    {
        if ($condition->isArchived()) {
            return $condition;
        }

        return DB::transaction(function () use ($condition): EntryCondition {
            $condition->forceFill(['archived_at' => now()])->save();

            event(new EntryConditionArchived(
                entryConditionId: (string) $condition->getKey(),
                brandId: $condition->brand_id,
                key: $condition->key,
                name: $condition->name,
                verification: $condition->verification->value,
            ));

            return $condition->refresh();
        });
    }
}
