<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Konventix\Messaging\Mail\MailQuota;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Platform\Models\Brand;

/**
 * The sending quota's arithmetic (bugfix round 2026-09-21, Task 14): which
 * budget a brand counts against, when a window reopens — in the budget's
 * own timezone for the day, which is the brand's only where the brand has
 * its own transport and UTC for the shared one (Round 4 Task 24) — and
 * that the counters expire with their window. Helper names prefixed —
 * Pest helpers are process-global.
 */
function mailQuotaSettingsFor(Brand $brand, array $attributes = []): BrandMailSettings
{
    return BrandMailSettings::factory()->create(['brand_id' => $brand->getKey(), ...$attributes]);
}

it('shares the default budget between brands without a transport and gives a brand with one its own', function (): void {
    config(['messaging.mail_quota.per_hour' => 100, 'messaging.mail_quota.per_day' => 500]);

    $bare = Brand::factory()->create(['slug' => 'mq-bare']);
    $configured = Brand::factory()->create(['slug' => 'mq-own']);
    mailQuotaSettingsFor($configured, ['transport' => ['transport' => 'array'], 'mail_quota_per_hour' => 20]);

    $quota = app(MailQuota::class);

    $shared = $quota->scopeFor($bare->getKey());
    expect($shared->key)->toBe(MailQuota::SHARED_SCOPE)
        ->and($shared->shared)->toBeTrue()
        ->and($shared->perHour)->toBe(100)
        ->and($shared->perDay)->toBe(500)
        ->and($shared->timezone)->toBe('UTC');

    $own = $quota->scopeFor($configured->getKey());
    expect($own->key)->toBe($configured->getKey())
        ->and($own->shared)->toBeFalse()
        ->and($own->perHour)->toBe(20)
        // Only the hourly override was set; the day keeps the default.
        ->and($own->perDay)->toBe(500);
});

it('counts a sent mail into the current hour and day and forgets it when the hour rolls over', function (): void {
    $brand = Brand::factory()->create(['slug' => 'mq-count']);
    $quota = app(MailQuota::class);
    $scope = $quota->scopeFor($brand->getKey());

    $this->travelTo(Carbon::parse('2026-09-21 14:20:00', 'UTC'));
    $quota->record($brand->getKey());
    $quota->record($brand->getKey());

    expect($quota->usage($scope))->toBe(['hour' => 2, 'day' => 2]);

    $this->travelTo(Carbon::parse('2026-09-21 15:00:00', 'UTC'));
    expect($quota->usage($scope))->toBe(['hour' => 0, 'day' => 2]);

    $this->travelTo(Carbon::parse('2026-09-22 00:00:00', 'UTC'));
    expect($quota->usage($scope))->toBe(['hour' => 0, 'day' => 0]);
});

it('holds until the next clock hour once the hourly budget is spent', function (): void {
    config(['messaging.mail_quota.per_hour' => 2, 'messaging.mail_quota.per_day' => 500]);
    $brand = Brand::factory()->create(['slug' => 'mq-hour']);
    $quota = app(MailQuota::class);
    $scope = $quota->scopeFor($brand->getKey());

    $this->travelTo(Carbon::parse('2026-09-21 14:20:00', 'UTC'));
    $quota->record($brand->getKey());

    expect($quota->holdFor($scope))->toBeNull();

    $quota->record($brand->getKey());

    $hold = $quota->holdFor($scope);
    expect($hold?->window)->toBe('hour')
        ->and($hold?->until->toDateTimeString())->toBe('2026-09-21 15:00:00');

    // Mail this caller has already decided to release counts as spent.
    $this->travelTo(Carbon::parse('2026-09-21 15:00:00', 'UTC'));
    expect($quota->holdFor($scope))->toBeNull()
        ->and($quota->holdFor($scope, reserved: 2)?->window)->toBe('hour');
});

it('closes the daily window at midnight in the brand timezone, and the later window wins', function (): void {
    config(['messaging.mail_quota.per_hour' => 1, 'messaging.mail_quota.per_day' => 1]);
    $brand = Brand::factory()->create(['slug' => 'mq-day']);
    // Its own transport, so its own budget — and with it its own midnight
    // (Task 24: only the shared budget is forced onto the UTC day).
    mailQuotaSettingsFor($brand, ['transport' => ['transport' => 'array'], 'timezone' => 'Europe/Berlin']);
    $quota = app(MailQuota::class);
    $scope = $quota->scopeFor($brand->getKey());

    expect($scope->key)->toBe($brand->getKey())
        ->and($scope->timezone)->toBe('Europe/Berlin');

    // 22:30 in Berlin (CEST): the Berlin day ends at 22:00 UTC, not at 00:00.
    $this->travelTo(Carbon::parse('2026-09-21 20:30:00', 'UTC'));
    $quota->record($brand->getKey());

    $hold = $quota->holdFor($scope);
    expect($hold?->window)->toBe('day')
        ->and($hold?->until->toDateTimeString())->toBe('2026-09-21 22:00:00');

    // Past Berlin midnight the day count is fresh even though the UTC date
    // has not changed — the counter and the hold agree on the same clock.
    $this->travelTo(Carbon::parse('2026-09-21 22:00:00', 'UTC'));
    expect($quota->usage($scope)['day'])->toBe(0)
        ->and($quota->holdFor($scope))->toBeNull();
});

it('rolls the shared budget day at UTC midnight even for a brand in another timezone', function (): void {
    // Task 24 (D-MSG-10): one mailbox account, one day. A brand on the
    // application's default mailer shares the `default` budget, so its own
    // mail-settings timezone must not move that budget's midnight — two
    // brands in different zones would otherwise spend overlapping days
    // against the same daily allowance.
    config(['messaging.mail_quota.per_hour' => 500, 'messaging.mail_quota.per_day' => 1]);
    $brand = Brand::factory()->create(['slug' => 'mq-shared-tz']);
    mailQuotaSettingsFor($brand, ['timezone' => 'Europe/Berlin']);
    $quota = app(MailQuota::class);
    $scope = $quota->scopeFor($brand->getKey());

    expect($scope->key)->toBe(MailQuota::SHARED_SCOPE)
        ->and($scope->shared)->toBeTrue()
        ->and($scope->timezone)->toBe('UTC');

    // 22:30 in Berlin, 20:30 UTC: the budget's day ends at 00:00 UTC.
    $this->travelTo(Carbon::parse('2026-09-21 20:30:00', 'UTC'));
    $quota->record($brand->getKey());

    $hold = $quota->holdFor($scope);
    expect($hold?->window)->toBe('day')
        ->and($hold?->until->toDateTimeString())->toBe('2026-09-22 00:00:00')
        ->and(Cache::get('messaging:mail-quota:default:d:20260921'))->toBe(1);

    // Berlin's midnight leaves the shared count where it was...
    $this->travelTo(Carbon::parse('2026-09-21 22:00:00', 'UTC'));
    expect($quota->usage($scope)['day'])->toBe(1)
        ->and($quota->holdFor($scope)?->window)->toBe('day');

    // ...and UTC midnight is what reopens it.
    $this->travelTo(Carbon::parse('2026-09-22 00:00:00', 'UTC'));
    expect($quota->usage($scope)['day'])->toBe(0)
        ->and($quota->holdFor($scope))->toBeNull();
});

it('seeds every counter with an expiry so a window can never live forever', function (): void {
    $brand = Brand::factory()->create(['slug' => 'mq-ttl']);
    $quota = app(MailQuota::class);

    $this->travelTo(Carbon::parse('2026-09-21 14:20:00', 'UTC'));
    $quota->record($brand->getKey());

    expect(Cache::get('messaging:mail-quota:default:h:2026092114'))->toBe(1)
        ->and(Cache::get('messaging:mail-quota:default:d:20260921'))->toBe(1);

    // The array store honours expiries: two hours on, the hour key is gone
    // by itself and the day key still stands; two days on, both are gone.
    $this->travelTo(Carbon::parse('2026-09-21 16:20:00', 'UTC'));
    expect(Cache::get('messaging:mail-quota:default:h:2026092114'))->toBeNull()
        ->and(Cache::get('messaging:mail-quota:default:d:20260921'))->toBe(1);

    $this->travelTo(Carbon::parse('2026-09-23 00:20:00', 'UTC'));
    expect(Cache::get('messaging:mail-quota:default:d:20260921'))->toBeNull();
});
