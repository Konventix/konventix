<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Compliance\Data\SubjectRequestMailData;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Throwable;

/**
 * Tells the data subject what happened to their request (Art. 12(3): the
 * controller informs the subject of the action taken).
 *
 * **Deletion is acknowledged when it starts, not when it finishes**, and that
 * is not an oversight. By the time a deletion completes, every module has
 * scrubbed the person's contact details — Messaging's recipient row included
 * — so a completion notice would have nowhere to go. The acknowledgment goes
 * out while the address is still valid and carries the statutory deadline;
 * the completion is recorded in the trail rather than mailed into the void.
 *
 * No `topicKey`: these are legal notices, not notifications a person may turn
 * off. The topic machinery (ADR-0041) exists to honour preferences, and there
 * is no preference to honour here — only the suppression list, which
 * `SendMessage` applies regardless.
 *
 * Registered explicitly in ComplianceServiceProvider (.ai/rules/listeners.md).
 */
final class NotifySubjectOfRequestOutcome
{
    public const string EXPORT_READY = 'compliance.export-ready';

    public const string DELETION_ACKNOWLEDGED = 'compliance.deletion-acknowledged';

    public const string REQUEST_REFUSED = 'compliance.request-refused';

    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handleExportCompleted(DataExportCompleted $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($event->subjectId, $event->brandId, self::EXPORT_READY, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: $request->deadline_at->format('d.m.Y'),
            availableUntil: $request->archive_expires_at?->format('d.m.Y'),
        ));
    }

    public function handleDeletionRequested(UserDeletionRequested $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($event->subjectId, $event->brandId, self::DELETION_ACKNOWLEDGED, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: $request->deadline_at->format('d.m.Y'),
        ));
    }

    public function handleRefused(SubjectRequestRefused $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($event->subjectId, $event->brandId, self::REQUEST_REFUSED, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: $request->deadline_at->format('d.m.Y'),
            reasonCode: $event->reasonCode,
        ));
    }

    /**
     * Best-effort by design. `SendMessage` refuses loudly when a subject has
     * no resolvable address — which is correct for Messaging, where silence
     * is the worst failure mode — but a subject request must not fail because
     * the acknowledgment could not be delivered. A subject may have no
     * account behind their ULID at all, or predate Messaging's recipient
     * projection (the replay gap in Identity's TODO).
     *
     * So the notice is attempted, and a failure is recorded rather than
     * thrown: the statutory obligation is to carry out the request, and the
     * operator console still shows its state.
     */
    private function send(string $subjectId, string $brandId, string $templateKey, SubjectRequestMailData $payload): void
    {
        try {
            $this->sendMessage->handle(new SendMessageRequest(
                // By ULID: Messaging resolves the address and locale from its
                // own recipient read model, and Compliance never learns either.
                recipientId: $subjectId,
                templateKey: $templateKey,
                payload: $payload,
                brandId: $brandId,
            ));
        } catch (Throwable $exception) {
            Log::warning('Could not notify the data subject about their request.', [
                'subject_id' => $subjectId,
                'template' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function find(string $requestId): ?DataSubjectRequest
    {
        /** @var DataSubjectRequest|null */
        return DataSubjectRequest::withoutBrandScope()->find($requestId);
    }
}
