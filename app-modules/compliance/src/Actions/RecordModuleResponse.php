<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;

/**
 * Records one module's response to a DataSubjectRequest's fan-out.
 *
 * Idempotent by construction: `(data_subject_request_id, module)` is unique,
 * so calling this twice for the same module — e.g. a module's listener
 * retrying after a transient failure, or reacting again after
 * RedispatchSubjectRequest re-fires the event — updates the same row instead
 * of creating a duplicate.
 */
final class RecordModuleResponse
{
    public function __invoke(DataSubjectRequest $request, string $module, ModuleResponseState $state): DataSubjectRequestResponse
    {
        return DB::transaction(fn (): DataSubjectRequestResponse => DataSubjectRequestResponse::query()->updateOrCreate(
            ['data_subject_request_id' => $request->id, 'module' => $module],
            ['state' => $state, 'responded_at' => $state === ModuleResponseState::Pending ? null : now()],
        ));
    }
}
