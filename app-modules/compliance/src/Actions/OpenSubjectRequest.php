<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;

/**
 * Opens a data subject request: sets the statutory 30-day deadline, seeds a
 * pending DataSubjectRequestResponse row for every currently registered
 * GdprHandler module, and fans out the SPEC event for export/deletion
 * requests.
 *
 * Rectification has no SPEC event yet — SPEC "Public contract" only proposes
 * DataExportRequested and UserDeletionRequested — so a rectification request
 * still opens (and gets a state to progress through), it just dispatches
 * nothing. TODO.md tracks defining a RectificationRequested event as a
 * follow-up.
 */
final class OpenSubjectRequest
{
    public function __construct(private readonly GdprHandlerRegistry $registry) {}

    public function __invoke(SubjectRef $subject, DataSubjectRequestType $type, ?DeletionMode $mode = null, ?string $filedOnBrandId = null): DataSubjectRequest
    {
        if ($type === DataSubjectRequestType::Deletion && $mode === null) {
            throw new InvalidArgumentException('Opening a deletion request requires a DeletionMode.');
        }

        return DB::transaction(function () use ($subject, $type, $mode, $filedOnBrandId): DataSubjectRequest {
            $request = DataSubjectRequest::create([
                'subject_id' => $subject->userId,
                'subject_email' => $subject->email,
                'filed_on_brand_id' => $filedOnBrandId,
                'type' => $type,
                'state' => DataSubjectRequestState::Received,
                'mode' => $mode,
                'deadline_at' => now()->addDays(30),
            ]);

            foreach ($this->registry->moduleNames() as $module) {
                DataSubjectRequestResponse::create([
                    'data_subject_request_id' => $request->id,
                    'module' => $module,
                    'state' => ModuleResponseState::Pending,
                ]);
            }

            $this->dispatchFanOut($request);

            return $request;
        });
    }

    private function dispatchFanOut(DataSubjectRequest $request): void
    {
        match ($request->type) {
            DataSubjectRequestType::Export => event(new DataExportRequested(
                requestId: $request->id,
                subjectId: $request->subject_id,
                subjectEmail: $request->subject_email,
                brandId: $request->filed_on_brand_id ?? '',
                requestedAt: $request->created_at->toIso8601String(),
            )),
            DataSubjectRequestType::Deletion => event(new UserDeletionRequested(
                requestId: $request->id,
                subjectId: $request->subject_id,
                subjectEmail: $request->subject_email,
                brandId: $request->filed_on_brand_id ?? '',
                mode: $request->mode->value,
            )),
            DataSubjectRequestType::Rectification => null,
        };
    }
}
