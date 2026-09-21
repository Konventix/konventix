<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\GuardsEntryConditionKey;
use Konventix\Registration\Data\EntryConditionData;
use Konventix\Registration\Events\EntryConditionCreated;
use Konventix\Registration\Models\EntryCondition;

/**
 * Writes what a reduced fare rests on (ADR-0066, spec §4.1, §6.1 step 1).
 *
 * `brand_id` is passed in and force-filled: the operator console creates
 * conditions for a brand it is not resolved into (ADR-0029).
 *
 * The key's uniqueness per brand is a database constraint *and* a refusal
 * here, because the key travels to the door and onto the ticket PDF: two
 * conditions of one brand sharing one would make the door's label
 * ambiguous, and a constraint violation in front of an operator says
 * nothing about which field to fix.
 */
final class CreateEntryCondition
{
    use GuardsEntryConditionKey;

    public function handle(string $brandId, EntryConditionData $data): EntryCondition
    {
        $this->guardKeyIsFree($brandId, $data->key);

        return DB::transaction(function () use ($brandId, $data): EntryCondition {
            $condition = new EntryCondition([
                'event_id' => $data->eventId,
                'key' => $data->key,
                'name' => $data->name,
                'description' => $data->description,
                'assertion_text' => $data->assertionText,
                'document_hint' => $data->documentHint,
                'verification' => $data->verification,
            ]);

            $condition->forceFill(['brand_id' => $brandId])->save();

            event(new EntryConditionCreated(
                entryConditionId: (string) $condition->getKey(),
                brandId: $brandId,
                key: $condition->key,
                name: $condition->name,
                verification: $condition->verification->value,
            ));

            return $condition;
        });
    }
}
