<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners;

use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\DunningNoticeData;
use Konventix\Messaging\Data\SendMessageRequest;

/**
 * The intent-event exception (ADR-0041 point 8): Commerce's dunning
 * contract deliberately says "Messaging alone decides what this means", so
 * for exactly this event the listener lives here — the general rule (a
 * module that wants a message sends one) stands for everything else.
 *
 * The billing contact may have no account, so the send is address-based;
 * German wording default, matching the invoice mail (ADR-0030's DACH
 * framing). The `commerce.dunning` topic is non-suppressible: a payment
 * reminder offers no opt-out, only the mail suppression list applies.
 */
final class SendDunningNotice
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(DunningLevelRaised $event): void
    {
        if ($event->billingEmail === null || $event->billingEmail === '') {
            // Invoice settlement guards the billing address at submission,
            // so this is belt and braces — a reminder with no address is
            // not a reason to abort the dunning sweep around it.
            return;
        }

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: null,
            templateKey: 'messaging.dunning-notice',
            payload: new DunningNoticeData(
                recipientName: $event->billingName,
                invoiceNumber: $event->invoiceNumber,
                level: $event->level,
                daysOverdue: $event->daysOverdue,
                totalGross: $event->totalGross,
                currency: $event->currency,
                dueOn: $event->dueOn,
            ),
            recipientEmail: $event->billingEmail,
            recipientLocale: 'de',
            brandId: $event->brandId,
            topicKey: 'commerce.dunning',
        ));
    }
}
