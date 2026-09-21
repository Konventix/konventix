<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners\Concerns;

use Illuminate\Support\Facades\Log;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Everything the two update mails of design §7 have in common: the consent
 * gate, the two ways of addressing a reporter, and the rule that a failed
 * send is a warning and never a failed request.
 *
 * **The consent gate is here and not at the call site**, so that adding a
 * third update mail cannot forget it. `contact_consent` is the answer to
 * "You may contact me about this report", and it governs everything sent
 * *after* the receipt. The receipt itself is the one exception in this
 * module and is sent by {@see AcknowledgeReporterOnRequestOpened}, which
 * deliberately does not use this trait: for a guest the receipt is the only
 * key to the report, and withholding it would mean accepting a report the
 * person can never read again (§7).
 *
 * The two ways of addressing a reporter are the account and the address:
 * an account is named by id, Messaging resolves their address and locale
 * from its own recipient read model, and the send rides the reporter's
 * topic so the bell shows it and their preference governs it. A guest is
 * named by the address stored on the row and rides no topic, because there
 * is no account to hold a preference — the same split the receipt makes.
 *
 * `SendMessage` is constructor-injected because it is a stateless Action,
 * not a scoped binding; anything request-scoped a listener needs is
 * resolved inside `handle()` instead (`.ai/rules/listeners.md`).
 */
trait ReachesTheReporter
{
    /**
     * A console-filed report has no brand, and Messaging needs one — the
     * stand-in rule is stated once, in the trait S1 wrote for it.
     */
    use ResolvesConsoleFallbacks;

    /**
     * Send one update to the reporter, if they asked to hear about it.
     *
     * Returns whether anything was sent, which is what the tests assert on
     * and what a caller may log; nothing downstream branches on it.
     */
    protected function tellTheReporter(
        SendMessage $sendMessage,
        SupportRequest $request,
        string $templateKey,
        Data $payload,
    ): bool {
        if (! $request->contact_consent) {
            return false;
        }

        $isGuest = $request->user_id === null;

        if ($isGuest && ($request->email === null || $request->email === '')) {
            // Erased on request (§10.5), or an account whose id was nulled.
            // There is nobody left to write to, and that is not a failure.
            return false;
        }

        $brandId = $request->filed_on_brand_id;

        if ($brandId === null) {
            $brandId = $this->messagingBrandId(null, $this->consoleOperatorId());
        }

        if ($brandId === null) {
            Log::warning('A support update had no brand to be sent on.', [
                'request_id' => $request->getKey(),
                'template' => $templateKey,
            ]);

            return false;
        }

        try {
            $sendMessage->handle(new SendMessageRequest(
                recipientId: $request->user_id,
                templateKey: $templateKey,
                payload: $payload,
                recipientEmail: $isGuest ? $request->email : null,
                brandId: $brandId,
                topicKey: $isGuest ? null : 'support.request-updates',
            ));
        } catch (Throwable $exception) {
            Log::warning('A support update could not be sent.', [
                'request_id' => $request->getKey(),
                'template' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
