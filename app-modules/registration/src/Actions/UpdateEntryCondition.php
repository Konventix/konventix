<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\GuardsEntryConditionKey;
use Konventix\Registration\Data\EntryConditionData;
use Konventix\Registration\Events\EntryConditionUpdated;
use Konventix\Registration\Models\EntryCondition;

/**
 * Edits a condition — its wording, its hint, how it is verified (ADR-0066,
 * spec §4.1).
 *
 * **Nothing here is frozen, and nothing here is retroactive** (D-REG-19,
 * T-6). A rewording changes what the *next* registrant is shown; what a
 * past registrant declared is `assertion_text_snapshot` on their own row and
 * is never rewritten. That is the same principle as consenting to a policy
 * *version*, and it is what lets the condition editor stay open after the
 * type has sold.
 *
 * An archived condition is edited as any other: its rows are history and its
 * name still prints beside them.
 */
final class UpdateEntryCondition
{
    use GuardsEntryConditionKey;

    public function handle(EntryCondition $condition, EntryConditionData $data): EntryCondition
    {
        $this->guardKeyIsFree($condition->brand_id, $data->key, (string) $condition->getKey());

        return DB::transaction(function () use ($condition, $data): EntryCondition {
            $condition->forceFill([
                'event_id' => $data->eventId,
                'key' => $data->key,
                'name' => $data->name,
                'description' => $data->description,
                'assertion_text' => $data->assertionText,
                'document_hint' => $data->documentHint,
                'verification' => $data->verification,
            ])->save();

            event(new EntryConditionUpdated(
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
