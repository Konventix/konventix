<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

/**
 * The budget a brand's mail counts against (`MailQuota`): its cache key,
 * the effective limits, and the timezone whose midnight closes the daily
 * window. `shared` says the brand sends through the application's default
 * mailer and therefore shares `key` — and the count behind it — with every
 * other brand that does.
 *
 * `timezone` is the *budget's* zone, not necessarily the brand's: a shared
 * budget is always `UTC` (Round 4 Task 24, D-MSG-10), because brands in
 * different zones would otherwise count overlapping days against the one
 * mailbox account the provider is metering. Only a brand with its own
 * transport carries its own mail-settings timezone here.
 */
final readonly class MailQuotaScope
{
    public function __construct(
        public string $key,
        public int $perHour,
        public int $perDay,
        public string $timezone,
        public bool $shared,
    ) {}
}
