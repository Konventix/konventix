<?php

declare(strict_types=1);

use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Messaging's delivery outcomes as counters (ADR-0059): a mail provider
 * going dark shows as a rising `failed` line before anyone complains.
 * GitLab #173.
 */
it('counts delivered, failed, retried and suppressed messages per channel', function (): void {
    event(new MessageDelivered('01M1', 'mail', 'prov-1', now()->toIso8601String()));
    event(new MessageFailed('01M2', 'mail', 'smtp_timeout', 1, true));
    event(new MessageFailed('01M3', 'mail', 'bounced', 3, false));
    event(new MessageSuppressed('01M4', '01BRAND', null, 'order.confirmation', 'mail', 'unsubscribed'));

    $rendered = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

    expect($rendered)
        ->toContain('konventix_messages_total{channel="mail",outcome="delivered"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="retry"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="failed"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="suppressed"} 1');
});
