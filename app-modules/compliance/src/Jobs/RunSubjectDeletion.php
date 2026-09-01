<?php

declare(strict_types=1);

namespace Konventix\Compliance\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Konventix\Compliance\Actions\RecordModuleResponse;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\UserDeletionCompleted;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;
use Throwable;

/**
 * Runs every registered module's deletion handler for one subject.
 *
 * The mirror image of `AssembleSubjectExport`, with one difference that
 * matters: a failed module here has already had its successful siblings run.
 * That is not a defect — deletion is per-module by design (each module
 * discharges its own obligation, SPEC "The line to hold"), and handlers are
 * required to be idempotent precisely so the re-dispatch that follows can
 * re-run all of them without undoing anything.
 *
 * The request only closes when every module reported success, so a partial
 * deletion stays visibly open in the console rather than looking discharged.
 */
final class RunSubjectDeletion implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $requestId,
        public readonly string $brandId,
    ) {}

    public function handle(GdprHandlerRegistry $registry, RecordModuleResponse $recordModuleResponse): void
    {
        /** @var DataSubjectRequest|null $request */
        $request = DataSubjectRequest::withoutBrandScope()
            ->where('brand_id', $this->brandId)
            ->whereKey($this->requestId)
            ->first();

        if ($request === null || $request->state === DataSubjectRequestState::Refused) {
            return;
        }

        $request->forceFill(['state' => DataSubjectRequestState::InProgress])->save();

        $mode = $request->mode ?? DeletionMode::Anonymize;
        $subject = SubjectRef::forUser($request->subject_id);
        $failed = false;

        foreach ($registry->all() as $module => $handler) {
            /** @var GdprHandler $handler */
            try {
                $handler->delete($subject, $mode);
                $recordModuleResponse($request, $module, ModuleResponseState::Completed);
            } catch (Throwable $exception) {
                $failed = true;
                $recordModuleResponse($request, $module, ModuleResponseState::Failed);

                Log::error('GDPR deletion handler failed', [
                    'request_id' => $request->getKey(),
                    'module' => $module,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($failed) {
            return;
        }

        $request->forceFill([
            'state' => DataSubjectRequestState::Fulfilled,
            'closed_at' => now(),
        ])->save();

        event(new UserDeletionCompleted(
            requestId: $request->getKey(),
            subjectId: $request->subject_id,
            brandId: $this->brandId,
            completedAt: now()->toIso8601String(),
        ));
    }
}
