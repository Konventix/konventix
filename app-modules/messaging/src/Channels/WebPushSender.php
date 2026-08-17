<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Konventix\Messaging\Contracts\PushSender;
use Konventix\Messaging\Data\PushMessageData;
use Konventix\Messaging\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The one class that speaks the web-push wire protocol (ADR-0041 point 7),
 * via minishlink/web-push directly — the pipeline, retries and logging are
 * what this module already owns, so a framework wrapper would only wrap.
 * One VAPID key pair per deployment, from `services.webpush`
 * (`messaging:vapid-keys` generates one).
 */
final class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    public function __construct(
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
    ) {}

    public function send(PushSubscription $subscription, PushMessageData $message): PushDeliveryReport
    {
        $report = $this->client()->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
                'contentEncoding' => $subscription->content_encoding,
            ]),
            json_encode($message->toArray(), JSON_THROW_ON_ERROR),
        );

        return new PushDeliveryReport(
            success: $report->isSuccess(),
            expired: $report->isSubscriptionExpired(),
            reason: $report->isSuccess() ? null : $report->getReason(),
        );
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush([
            'VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);
    }
}
