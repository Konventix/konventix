<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Data\TestMailData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * "Send test mail to me" (Task 8, ADR-0048 decisions 4/6): an operator
 * confirming a brand's sender identity and the branded layout actually
 * render, addressed to their own account email — never a domain event, and
 * never logged as anything other than what it is (`messaging.test-mail`).
 */
final class SendTestMail
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
    ) {}

    public function handle(Brand $brand, string $toEmail): void
    {
        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: null,
            templateKey: 'messaging.test-mail',
            payload: new TestMailData(
                brandName: $brand->name,
                date: $this->dates->date(now(), $this->brandTimezone->handle($brand->getKey())),
            ),
            recipientEmail: $toEmail,
            recipientLocale: 'de',
            brandId: $brand->getKey(),
        ));
    }
}
