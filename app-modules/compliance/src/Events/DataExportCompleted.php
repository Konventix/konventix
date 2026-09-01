<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when every registered handler has contributed and the archive
 * exists (SPEC "Public contract"). `NotifySubjectOfRequestOutcome` notifies
 * the subject from this; the event itself carries the expiry rather than a
 * URL, because the download link — where one is sent at all — is minted at
 * send time from the loaded request, not decided when the archive is
 * assembled.
 *
 * An account-backed subject's download stays unlinked, authorized per
 * request rather than handed out as a link with its own life
 * (`DataRequestController::download`). A subject with no account cannot
 * sign in to use that path at all, so for them the alternative is not a
 * worse link — it is no delivery at all — and the listener mails a single-
 * use, hashed token instead (ADR-0050 §9).
 */
final class DataExportCompleted
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $subjectId,
        public readonly ?string $subjectEmail,
        public readonly string $brandId,
        public readonly string $archiveExpiresAt,
    ) {}
}
