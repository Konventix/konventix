<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * Re-runs the fan-out for a request that partially failed.
 *
 * Semantics (documented per TODO.md "Next" requirement that re-dispatch be
 * idempotent): this re-emits the same export/deletion event to every
 * registered GdprHandler, unconditionally. It does NOT attempt to address
 * only the modules whose response is missing or failed, because Compliance
 * has no visibility into which of a module's own rows a handler already
 * touched — it never queries a foreign table (ADR-0004). The safe,
 * idempotent unit of retry is therefore "run the whole handler again":
 * SPEC's public contract requires every GdprHandler to be idempotent for
 * exactly this reason ("Compliance re-runs the fan-out rather than tracking
 * which rows a module already touched").
 *
 * This action does not itself touch DataSubjectRequestResponse rows. A
 * handler that reprocesses the re-dispatched event is expected to call
 * RecordModuleResponse again, which simply overwrites its own row. So
 * calling this on a request with no failures is harmless, and calling it
 * more than once in a row is equally harmless — both are exercised as
 * "re-dispatch idempotency" in tests.
 *
 * No DB::transaction here: this action performs no writes of its own, only
 * event dispatch.
 */
final class RedispatchSubjectRequest
{
    public function __invoke(DataSubjectRequest $request): void
    {
        match ($request->type) {
            DataSubjectRequestType::Export => event(new DataExportRequested(
                requestId: $request->id,
                subjectId: $request->subject_id,
                brandId: $request->brand_id,
                requestedAt: now()->toIso8601String(),
            )),
            DataSubjectRequestType::Deletion => event(new UserDeletionRequested(
                requestId: $request->id,
                subjectId: $request->subject_id,
                brandId: $request->brand_id,
                mode: $request->mode->value,
            )),
            DataSubjectRequestType::Rectification => null,
        };
    }
}
