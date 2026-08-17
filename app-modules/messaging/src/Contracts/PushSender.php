<?php

declare(strict_types=1);

namespace Konventix\Messaging\Contracts;

use Konventix\Messaging\Channels\PushDeliveryReport;
use Konventix\Messaging\Data\PushMessageData;
use Konventix\Messaging\Models\PushSubscription;

/**
 * The seam in front of the web-push wire protocol (ADR-0041 point 7) —
 * the same shape Commerce gives the Stripe SDK (`StripeApi`): tests run on
 * a fake, only `WebPushSender` talks to a push service.
 */
interface PushSender
{
    public function send(PushSubscription $subscription, PushMessageData $message): PushDeliveryReport;
}
