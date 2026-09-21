<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Support\Data\SupportReceiptData;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Listeners\Concerns\ResolvesConsoleFallbacks;
use Konventix\Support\Models\SupportRequest;
use Throwable;

/**
 * The reporter's half of `SupportRequestOpened` (design §7): the receipt.
 *
 * **Sent regardless of `contact_consent`**, which is the one place in this
 * module where consent does not gate a mail, and deliberately so. For an
 * account the receipt is a courtesy on top of a page they can always open.
 * For a guest it *is* the request: the signed link in it is the only key
 * that exists to the report they just filed (§8.2), and withholding it
 * because they did not tick "you may contact me" would mean accepting a
 * report the person can never read again. Consent governs what we send
 * them **afterwards** — the state changes and the replies of S3 — and
 * those listeners check it.
 *
 * An account's receipt rides the reporter's own topic
 * (`support.request-updates`), so it also lands in the bell and obeys the
 * preference the person set. A guest's does not: there is no account to
 * hold a preference, and the mail is the access.
 *
 * Best-effort like every send in this module. A receipt that could not be
 * delivered is a warning in the log; the report itself is already stored,
 * and failing the submission at this point would lose the report to save
 * the acknowledgement of it.
 */
final class AcknowledgeReporterOnRequestOpened
{
    use ResolvesConsoleFallbacks;

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly SupportLinks $links,
    ) {}

    public function handle(SupportRequestOpened $event): void
    {
        $request = SupportRequest::query()->whereKey($event->requestId)->first();

        if ($request === null) {
            return;
        }

        $brandId = $this->messagingBrandId($event->brandId, $this->consoleOperatorId());

        if ($brandId === null) {
            Log::warning('A support receipt had no brand to be sent on.', [
                'request_id' => $request->getKey(),
            ]);

            return;
        }

        $isGuest = $request->user_id === null;
        $url = $this->links->reporterUrl($request);

        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $request->user_id,
                templateKey: 'support.request-received',
                payload: new SupportReceiptData(
                    reference: $request->reference(),
                    categories: $this->categoryLabels($request),
                    requestUrl: $url,
                    accessNoteDe: $isGuest
                        ? 'Über den Link unten können Sie Ihre Meldung 30 Tage lang ansehen und beantworten. Bitte bewahren Sie diese E-Mail auf — sie ist Ihr Zugang zu der Meldung.'
                        : 'Sie finden diese Meldung jederzeit in Ihrem Konto unter „Meine Meldungen“.',
                    accessNoteEn: $isGuest
                        ? 'The link below lets you read and answer your report for the next 30 days. Please keep this e-mail — it is your access to the report.'
                        : 'You can find this report in your account under “My support requests” at any time.',
                ),
                // A guest has no account behind the address, so the address
                // is given explicitly — Messaging's documented shape for
                // reaching a stranger.
                recipientEmail: $isGuest ? $request->email : null,
                brandId: $brandId,
                // No topic for a guest: there is no account to hold a
                // preference, and this mail is the access itself.
                topicKey: $isGuest ? null : 'support.request-updates',
            ));
        } catch (Throwable $exception) {
            Log::warning('A support receipt could not be sent.', [
                'request_id' => $request->getKey(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function categoryLabels(SupportRequest $request): string
    {
        $labels = array_map(
            fn (SupportCategory $category): string => $category->label(),
            $request->categoryCases(),
        );

        return $labels === [] ? 'No category' : implode(', ', $labels);
    }
}
