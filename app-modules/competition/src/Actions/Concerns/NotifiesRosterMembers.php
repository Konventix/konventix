<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Illuminate\Support\Facades\Log;
use Konventix\Competition\Actions\DeclineIncompleteEntrant;
use Konventix\Competition\Actions\FillIncompleteEntrant;
use Konventix\Competition\Data\EntrantNoticeData;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Throwable;

/**
 * Tells people what just happened to their entry (design §5.4).
 *
 * Messaging is asked through its published send API and nothing else
 * (ADR-0004); `brandId` is stated explicitly because both callers run from
 * the operator console, where no brand is in context (ADR-0029).
 *
 * **A failed send never fails the Action.** Filling a roster or declining one
 * is a decision the organizer made about the tournament; a bounced address
 * must not roll it back and leave the console showing a team that is still
 * incomplete. It is logged instead, which is the same trade Support makes for
 * its triage notices.
 *
 * `SendMessage` is resolved inside the `try`, per recipient, rather than
 * injected or resolved once above the loop. Two reasons, and both have bitten
 * this codebase: an Action holding a service resolved from the boot-time
 * container reads the wrong scoped bindings under Octane
 * (`.ai/rules/listeners.md`), and a resolution that throws — a brand with
 * Messaging switched off, a missing binding — must be caught by the same
 * handler that catches a bounced send, not escape past it and roll back the
 * organizer's decision.
 *
 * @see FillIncompleteEntrant
 * @see DeclineIncompleteEntrant
 */
trait NotifiesRosterMembers
{
    /**
     * @param  list<string>  $userIds
     */
    protected function notifyRoster(
        array $userIds,
        string $brandId,
        string $templateKey,
        EntrantNoticeData $payload,
    ): void {
        if ($userIds === []) {
            return;
        }

        foreach (array_unique($userIds) as $userId) {
            try {
                app(SendMessage::class)->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: $templateKey,
                    payload: $payload,
                    brandId: $brandId,
                ));
            } catch (Throwable $exception) {
                Log::warning('A competition roster notice could not be sent.', [
                    'template' => $templateKey,
                    'user_id' => $userId,
                    'brand_id' => $brandId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
