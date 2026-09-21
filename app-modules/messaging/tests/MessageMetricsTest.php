<?php

declare(strict_types=1);

use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Mail\MailQuota;
use Konventix\Messaging\Mail\MailQuotaHold;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Platform\Models\Brand;
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

function messagingMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('reports the mail quota used and its limit per brand and window, every brand included', function (): void {
    // Task 14 (ADR-0059): a brand that sent nothing still reports 0, so a
    // row never outlives the window it was counted in.
    config(['messaging.mail_quota.per_hour' => 100, 'messaging.mail_quota.per_day' => 500]);
    $busy = Brand::factory()->create(['slug' => 'mm-busy']);
    $quiet = Brand::factory()->create(['slug' => 'mm-quiet']);
    BrandMailSettings::factory()->create(['brand_id' => $busy->getKey(), 'transport' => ['transport' => 'array'], 'mail_quota_per_day' => 40]);

    $quota = app(MailQuota::class);
    $quota->record($busy->getKey());
    $quota->record($busy->getKey());
    $quota->record($busy->getKey());

    expect(messagingMetricsScrape())
        ->toContain('konventix_mail_quota_used{brand="mm-busy",window="hour"} 3')
        ->toContain('konventix_mail_quota_used{brand="mm-busy",window="day"} 3')
        ->toContain('konventix_mail_quota_limit{brand="mm-busy",window="hour"} 100')
        ->toContain('konventix_mail_quota_limit{brand="mm-busy",window="day"} 40')
        ->toContain('konventix_mail_quota_used{brand="mm-quiet",window="hour"} 0')
        ->toContain('konventix_mail_quota_used{brand="mm-quiet",window="day"} 0')
        ->toContain('konventix_mail_quota_limit{brand="mm-quiet",window="day"} 500');
});

it('counts mail held for the quota per brand slug and window', function (): void {
    $brand = Brand::factory()->create(['slug' => 'mm-held']);

    app(MailQuota::class)->recordHold($brand->getKey(), new MailQuotaHold(now()->toImmutable()->addHour(), 'hour'));

    $rendered = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

    expect($rendered)->toContain('konventix_mail_quota_held_total{brand="mm-held",window="hour"} 1');
});
