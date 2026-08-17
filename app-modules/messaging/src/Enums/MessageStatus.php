<?php

declare(strict_types=1);

namespace Konventix\Messaging\Enums;

/**
 * A delivery-log row's lifecycle (ADR-0041). `Held` is the digest/quiet-
 * hours state — visible in the log, never silently swallowed; the sweep
 * moves it on. `Sent` means the transport accepted the message; provider
 * callbacks may refine that later, they never rewrite it.
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Held = 'held';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Refused before dispatch — suppression list or preferences. The one
     * failure mode a messaging pipeline must not have is silence; this row
     * plus `MessageSuppressed` is how a caller learns the platform
     * declined to send.
     */
    case Suppressed = 'suppressed';
}
