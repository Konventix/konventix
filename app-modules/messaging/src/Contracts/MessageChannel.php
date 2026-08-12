<?php

declare(strict_types=1);

namespace Konventix\Messaging\Contracts;

use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Templates\RenderedMessage;

/**
 * The driver contract every delivery channel implements (mail first, in-app
 * and push to follow — see TODO.md Backlog).
 *
 * A channel is handed the queued message envelope plus its already-rendered
 * content; it owns only delivery mechanics (which transport, which provider
 * SDK), never template selection or rendering. That split is what lets the
 * template registry and renderer stay channel-agnostic.
 */
interface MessageChannel
{
    /**
     * Deliver a rendered message to its recipient.
     *
     * Implementations resolve their own delivery mechanics — the mail driver
     * resolves the sending brand's transport from BrandContext at send time
     * (ADR-0028); brand context is already restored by the time this runs,
     * because Platform's queue propagation restores it before the job handles
     * (see PropagatesBrandContextToQueue).
     */
    public function send(SendMessageData $message, RenderedMessage $rendered): void;
}
