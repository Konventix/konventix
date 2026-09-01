<?php

declare(strict_types=1);

namespace Konventix\Compliance\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Konventix\Compliance\Actions\RecordModuleResponse;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Models\DataSubjectRequest;
use Throwable;

/**
 * Collects every registered module's contribution into one archive — the
 * first real caller of `GdprHandlerRegistry::all()`, and the thing that made
 * `DataExportRequested` more than an announcement.
 *
 * Compliance calls each handler through the contract rather than querying a
 * foreign table (ADR-0004): it never learns *where* the data lives, only
 * what each module says about the subject.
 *
 * One module failing does not sink the export. Its response row records
 * `failed` with the reason logged, the others still contribute, and the
 * request stays open — re-dispatch re-runs the whole fan-out, which is safe
 * because handlers are required to be idempotent. Only a fan-out where every
 * module reported success writes the archive and closes the request; a
 * partial archive presented as "your data" would be worse than a late one.
 *
 * The archive is JSON: a machine-readable, commonly used format
 * (Art. 20(1) GDPR), one top-level key per module.
 */
final class AssembleSubjectExport implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Retries help against transient storage failures only. */
    public int $tries = 3;

    /** Long enough to hand over, short enough not to become a second data store. */
    public const int ARCHIVE_DAYS = 14;

    public function __construct(
        public readonly string $requestId,
    ) {}

    public function handle(GdprHandlerRegistry $registry, RecordModuleResponse $recordModuleResponse): void
    {
        /** @var DataSubjectRequest|null $request */
        $request = DataSubjectRequest::query()->find($this->requestId);

        if ($request === null || $request->state === DataSubjectRequestState::Refused) {
            return;
        }

        $request->forceFill(['state' => DataSubjectRequestState::InProgress])->save();

        $subject = $request->subject();

        $contributions = [];
        $failed = false;

        foreach ($registry->all() as $module => $handler) {
            /** @var GdprHandler $handler */
            try {
                $contributions[$module] = $handler->exportFor($subject);
                $recordModuleResponse($request, $module, ModuleResponseState::Completed);
            } catch (Throwable $exception) {
                $failed = true;
                $recordModuleResponse($request, $module, ModuleResponseState::Failed);

                Log::error('GDPR export handler failed', [
                    'request_id' => $request->getKey(),
                    'module' => $module,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($failed) {
            return;
        }

        $expiresAt = now()->addDays(self::ARCHIVE_DAYS);

        // No brand in the path: one archive per request, for the whole
        // operator (ADR-0050 §1). `archive_disk` is sealed on the row, so
        // archives written under the old {brandId}/ path stay readable.
        $path = sprintf('subject-exports/%s.json', $request->getKey());
        $disk = (string) config('filesystems.exports_disk', 'exports');

        Storage::disk($disk)->put($path, (string) json_encode([
            // Exactly one of these is set (ADR-0050 §3) — an email-only
            // archive still names who it is about, rather than a bare null.
            'subject_id' => $request->subject_id,
            'subject_email' => $request->subject_email,
            'request_id' => $request->getKey(),
            'generated_at' => now()->toIso8601String(),
            'modules' => $contributions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $request->forceFill([
            'archive_path' => $path,
            // Sealed beside the path (ADR-0043) so the archive stays readable
            // if the deployment points new exports at another disk.
            'archive_disk' => $disk,
            'archive_expires_at' => $expiresAt,
            'state' => DataSubjectRequestState::Fulfilled,
            'closed_at' => now(),
        ])->save();

        event(new DataExportCompleted(
            requestId: $request->getKey(),
            subjectId: $request->subject_id,
            subjectEmail: $request->subject_email,
            brandId: $request->filed_on_brand_id ?? '',
            archiveExpiresAt: $expiresAt->toIso8601String(),
        ));
    }
}
