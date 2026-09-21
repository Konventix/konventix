<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Support\Data\SupportNoticeData;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Events\SupportRequestMessagePosted;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Listeners\Concerns\ResolvesConsoleFallbacks;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;
use Konventix\Support\Queries\TriageHolders;
use Throwable;

/**
 * The reporter answered — the operators' half of
 * `SupportRequestMessagePosted` (design §7, §8).
 *
 * A reporter's own reply mails the reporter nobody (that would be telling
 * them what they just typed) and has to reach the people who can act on
 * it, or the thread is a place where answers go to be unread. So it rides
 * the same road S1 built for a new report: the `support.requests` topic,
 * the `support.request-notice` template, and {@see TriageHolders} for who
 * counts as "the operators" — triage holders on the brand the report was
 * filed on, or the console's own `platform.access-admin` holders for a
 * report that has no brand (D-SUP-12). Reusing the template rather than
 * adding a fourth one keeps every operator notice the same three fields
 * and keeps the override editor's job unchanged.
 *
 * `urgent: true` for the same reason the opened notice is urgent: the
 * topic is digestable for calmer sends later, and somebody adding detail
 * to "my purchase is broken" is not one of them.
 *
 * Every send is wrapped on its own — one hard-bounced address must not cost
 * the other four their notice — and no notification failure ever fails the
 * reply that caused it.
 */
final class NotifyOperatorsOnReporterReply
{
    use ResolvesConsoleFallbacks;

    /** How much of the reply the notice quotes (design §7's shape). */
    private const int DETAIL_EXCERPT = 200;

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly SupportLinks $links,
    ) {}

    public function handle(SupportRequestMessagePosted $event): void
    {
        if ($event->visibility !== MessageVisibility::External->value) {
            return;
        }

        $request = SupportRequest::query()->whereKey($event->requestId)->first();

        if ($request === null) {
            return;
        }

        // The reporter's own entry, and nothing else: a guest writes with
        // no author id at all, an account writes with their own.
        if ($event->authorUserId !== null && $event->authorUserId !== $request->user_id) {
            return;
        }

        $message = SupportRequestMessage::query()->whereKey($event->messageId)->first();

        if ($message === null) {
            return;
        }

        // Scoped bindings inside `handle()`: `TriageHolders` reads
        // `OperatorContext`, and a listener built by the boot-time
        // dispatcher would otherwise carry the first request's operator for
        // the worker's whole life (`.ai/rules/listeners.md`).
        $recipients = app(TriageHolders::class)->forBrand($request->filed_on_brand_id);
        $messageBrandId = $this->messagingBrandId($request->filed_on_brand_id, $this->consoleOperatorId());

        if ($recipients === [] || $messageBrandId === null) {
            Log::warning('A support reply reached nobody: no holder or no brand to send on.', [
                'request_id' => $request->getKey(),
                'brand_id' => $request->filed_on_brand_id,
                'holders' => count($recipients),
            ]);

            return;
        }

        $reference = $request->reference();

        $payload = new SupportNoticeData(
            reference: $reference,
            title: "New reply on support request {$reference}",
            detail: Str::limit($message->body, self::DETAIL_EXCERPT),
            url: $this->links->consoleUrl($request),
        );

        foreach ($recipients as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'support.request-notice',
                    payload: $payload,
                    brandId: $messageBrandId,
                    topicKey: 'support.requests',
                    urgent: true,
                ));
            } catch (Throwable $exception) {
                Log::warning('A support reply notice could not be sent.', [
                    'request_id' => $request->getKey(),
                    'user_id' => $userId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
