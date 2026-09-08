<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Compliance\Data\SubjectRequestMailData;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
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

    /**
     * The public page's verification link (ADR-0050 §4). Sent by
     * `PublicDataRequestController::store`, not by a listener here — the
     * request this notice is about does not exist yet.
     */
    public const string REQUEST_VERIFICATION = 'compliance.request-verification';

    /**
     * The Art. 12(3) acknowledgement shared by export and deletion
     * (ADR-0050 §10). `handleExportRequested()` below sends it on
     * `DataExportRequested`; `UserDeletionRequested` keeps its own,
     * pre-existing `DELETION_ACKNOWLEDGED` instead of this one.
     */
    public const string REQUEST_ACKNOWLEDGED = 'compliance.request-acknowledged';

    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handleExportCompleted(DataExportCompleted $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $subject = $request->subject();

        // An account-backed subject is pointed at Settings → Privacy, same
        // as today. A subject with no account has no such page to sign into
        // (ADR-0050 §9) — the mail carries a freshly issued single-use
        // download link instead, mailed to the address they already
        // verified. Issued here, at send time, not earlier: a link minted
        // when nobody has asked for it yet is a link that can go stale
        // before it is ever read.
        $downloadUrl = $subject->isAccountBacked()
            ? null
            : route('data-requests.public.download', ['token' => $request->issueArchiveToken()]);

        $this->send($subject, $request->filed_on_brand_id, self::EXPORT_READY, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: app(OperatorDateTimeFormat::class)->date($request->deadline_at),
            availableUntil: $request->archive_expires_at === null ? null : app(OperatorDateTimeFormat::class)->date($request->archive_expires_at),
            downloadUrl: $downloadUrl,
        ));
    }

    public function handleDeletionRequested(UserDeletionRequested $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($request->subject(), $request->filed_on_brand_id, self::DELETION_ACKNOWLEDGED, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: app(OperatorDateTimeFormat::class)->date($request->deadline_at),
        ));
    }

    public function handleExportRequested(DataExportRequested $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($request->subject(), $request->filed_on_brand_id, self::REQUEST_ACKNOWLEDGED, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: app(OperatorDateTimeFormat::class)->date($request->deadline_at),
        ));
    }

    public function handleRefused(SubjectRequestRefused $event): void
    {
        $request = $this->find($event->requestId);

        if ($request === null) {
            return;
        }

        $this->send($request->subject(), $request->filed_on_brand_id, self::REQUEST_REFUSED, new SubjectRequestMailData(
            requestType: $request->type->value,
            deadline: app(OperatorDateTimeFormat::class)->date($request->deadline_at),
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
     *
     * `$subject` is `SubjectRef` (ADR-0050 §3): an account-backed subject is
     * addressed by ULID, resolved through Messaging's own recipient read
     * model; an account-less one has only the address they filed with,
     * passed straight through as `recipientEmail` the same way an exhibitor
     * billing contact already is. Every caller builds it from the loaded
     * request via `$request->subject()`, never from the event — the event's
     * own `subjectId`/`subjectEmail` exist for the audit trail, not for
     * addressing the mail.
     *
     * `$brandId` is `null` when the request carries no `filed_on_brand_id`
     * (ADR-0050 §1: provenance only, nullable), and an empty string is
     * treated the same way — Messaging's `brand_id` column is a real foreign
     * key, so neither is fed to it as a value, which lets `SendMessage` fall
     * back to the ambient `BrandContext` the way an unset override already
     * does.
     */
    private function send(SubjectRef $subject, ?string $brandId, string $templateKey, SubjectRequestMailData $payload): void
    {
        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $subject->userId,
                templateKey: $templateKey,
                payload: $payload,
                recipientEmail: $subject->email,
                brandId: $brandId === null || $brandId === '' ? null : $brandId,
            ));
        } catch (Throwable $exception) {
            Log::warning('Could not notify the data subject about their request.', [
                'subject_id' => $subject->userId,
                'subject_email' => $subject->email,
                'template' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function find(string $requestId): ?DataSubjectRequest
    {
        /** @var DataSubjectRequest|null */
        return DataSubjectRequest::query()->find($requestId);
    }
}
