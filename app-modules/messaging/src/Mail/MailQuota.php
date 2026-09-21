<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * The sending quota per hour and per day (bugfix round 2026-09-21, Task
 * 14): the deployment's default transport sits behind a mailbox provider
 * that refuses mail past 100 recipients an hour and 500 a day (netcup,
 * `config/messaging.php`), and a burst — a published announcement, a
 * sale's confirmations — used to hit that wall and fail on the transport.
 * `SendMessage` now asks this class before dispatching a non-urgent mail
 * and holds it (`hold_reason = mail_quota`) until the next window opens;
 * `Actions\FlushHeldMessages` releases holds as themselves, asking again
 * so an exhausted window re-holds instead of bursting.
 *
 * **Scope.** A brand with its own SMTP transport has its own budget, keyed
 * by its brand id; every brand sending through the application's default
 * mailer shares one budget (`default`), because the provider counts the
 * one account they share. The limits are the brand's own row values where
 * set, else the configured defaults.
 *
 * **Counting.** Cache counters per scope and window — `h:<YmdH>` in UTC,
 * `d:<Ymd>` in the scope's timezone — incremented by `Jobs\SendQueuedMessage`
 * where `sent_at` is written, on the mail channel only: a failed send does
 * not count, an urgent one does. Each key is created with `Cache::add()`
 * carrying a TTL past the window's end and then `Cache::increment()`ed,
 * which is atomic on Redis and on the database store alike and never
 * leaves a counter without an expiry.
 *
 * **Windows.** The hourly window is the UTC clock hour. The daily window
 * runs midnight to midnight in the brand's mail-settings timezone where
 * the row has one, else UTC — the same zone the hold is computed in, so
 * the counter and the release never disagree about when a day ends.
 */
final class MailQuota
{
    public const string HOLD_REASON = 'mail_quota';

    public const string SHARED_SCOPE = 'default';

    public const string WINDOW_HOUR = 'hour';

    public const string WINDOW_DAY = 'day';

    public function scopeFor(string $brandId): MailQuotaScope
    {
        // Keyed by the message's own brand, never ambient context: the
        // envelope names it, and a flush sweep runs for every brand at once.
        $settings = BrandMailSettings::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->first();

        $transport = $settings?->transport;
        $shared = $settings === null || $transport === null || $transport === [];

        return new MailQuotaScope(
            key: $shared ? self::SHARED_SCOPE : $brandId,
            perHour: max(1, $settings->mail_quota_per_hour ?? (int) config('messaging.mail_quota.per_hour')),
            perDay: max(1, $settings->mail_quota_per_day ?? (int) config('messaging.mail_quota.per_day')),
            timezone: $settings->timezone ?? 'UTC',
            shared: $shared,
        );
    }

    /**
     * Mail already sent in the scope's current hour and day.
     *
     * @return array{hour: int, day: int}
     */
    public function usage(MailQuotaScope $scope, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            self::WINDOW_HOUR => (int) Cache::get($this->hourKey($scope, $now), 0),
            self::WINDOW_DAY => (int) Cache::get($this->dayKey($scope, $now), 0),
        ];
    }

    /**
     * Null while one more mail fits into both windows, else when the next
     * free window opens. `$reserved` is mail this caller has already
     * decided to release but which has not reached the transport yet —
     * the flush sweep counts its own releases so one run cannot let a
     * whole hour's worth through on a counter that still reads zero.
     */
    public function holdFor(MailQuotaScope $scope, int $reserved = 0, ?CarbonImmutable $now = null): ?MailQuotaHold
    {
        $now ??= CarbonImmutable::now();
        $usage = $this->usage($scope, $now);

        $hold = null;

        if ($scope->perHour <= $usage[self::WINDOW_HOUR] + $reserved) {
            $hold = new MailQuotaHold($now->utc()->addHour()->startOfHour(), self::WINDOW_HOUR);
        }

        if ($scope->perDay <= $usage[self::WINDOW_DAY] + $reserved) {
            $tomorrow = $now->setTimezone($scope->timezone)->addDay()->startOfDay()->utc();

            if ($hold === null || $tomorrow->greaterThan($hold->until)) {
                $hold = new MailQuotaHold($tomorrow, self::WINDOW_DAY);
            }
        }

        return $hold;
    }

    /**
     * One mail left the transport for this brand: count it in both windows.
     */
    public function record(string $brandId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $scope = $this->scopeFor($brandId);

        // `add()` seeds the key with a TTL only when it is absent (SET NX on
        // Redis, an insert-or-nothing on the database store), so the
        // increment that follows never creates a counter that lives forever.
        $hourKey = $this->hourKey($scope, $now);
        Cache::add($hourKey, 0, $now->utc()->addHour()->startOfHour()->addHour());
        Cache::increment($hourKey);

        $dayKey = $this->dayKey($scope, $now);
        Cache::add($dayKey, 0, $now->setTimezone($scope->timezone)->addDay()->startOfDay()->addDay());
        Cache::increment($dayKey);
    }

    /**
     * A mail was held for the quota (ADR-0059): counted per brand slug and
     * the window that closed it. Called where the hold is decided —
     * `SendMessage` at accept time, the flush sweep on a re-hold.
     */
    public function recordHold(string $brandId, MailQuotaHold $hold): void
    {
        app(Metrics::class)->increment('mail_quota_held_total', [
            'brand' => BrandSlugs::of($brandId),
            'window' => $hold->window,
        ]);
    }

    private function hourKey(MailQuotaScope $scope, CarbonImmutable $now): string
    {
        return 'messaging:mail-quota:'.$scope->key.':h:'.$now->utc()->format('YmdH');
    }

    private function dayKey(MailQuotaScope $scope, CarbonImmutable $now): string
    {
        return 'messaging:mail-quota:'.$scope->key.':d:'.$now->setTimezone($scope->timezone)->format('Ymd');
    }
}
