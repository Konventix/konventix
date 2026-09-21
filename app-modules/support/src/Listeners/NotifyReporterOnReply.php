<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners;

use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Support\Data\SupportReplyNoticeData;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Events\SupportRequestMessagePosted;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Listeners\Concerns\ReachesTheReporter;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * An operator wrote back — the reporter's half of
 * `SupportRequestMessagePosted` (design §7).
 *
 * Two conditions, and both are load-bearing:
 *
 * - **`external` only.** An internal note is staff-only forever (§4.2).
 *   The event deliberately carries the visibility and not the body, so
 *   this listener can decide without ever having the note's text in reach;
 *   the reply's text is loaded below, after the decision, and only for the
 *   external case.
 * - **Not the reporter's own.** A person who just typed into their own
 *   thread does not need a mail telling them so. For a guest that is
 *   `authorUserId === null`; for an account it is the author being the
 *   reporter. The operators' notice about that reply is
 *   {@see NotifyOperatorsOnReporterReply}'s job — the other direction of
 *   the same event.
 *
 * Consent governs the send ({@see ReachesTheReporter}). Without it, a
 * reporter reads the reply on their own page and gets no mail about it —
 * which is the promise the checkbox made, and also why the console's
 * reporter card prints whether consent was given: an operator answering a
 * report nobody will be told about should know that before they write.
 *
 * Idempotent in the sense available to a send: it writes nothing and reads
 * both rows fresh.
 */
final class NotifyReporterOnReply
{
    use ReachesTheReporter;

    /** How much of the reply the mail quotes before pointing at the page. */
    private const int EXCERPT = 200;

    public function __construct(private readonly SupportLinks $links) {}

    public function handle(SupportRequestMessagePosted $event): void
    {
        if ($event->visibility !== MessageVisibility::External->value) {
            return;
        }

        if ($event->authorUserId === null) {
            return;
        }

        $request = SupportRequest::query()->whereKey($event->requestId)->first();

        if ($request === null || $event->authorUserId === $request->user_id) {
            return;
        }

        $message = SupportRequestMessage::query()->whereKey($event->messageId)->first();

        if ($message === null) {
            return;
        }

        $this->tellTheReporter(
            sendMessage: app(SendMessage::class),
            request: $request,
            templateKey: 'support.request-replied',
            payload: new SupportReplyNoticeData(
                reference: $request->reference(),
                excerpt: Str::limit($message->body, self::EXCERPT),
                requestUrl: $this->links->reporterUrl($request),
            ),
        );
    }
}
