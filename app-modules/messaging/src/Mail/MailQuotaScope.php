<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

/**
 * The budget a brand's mail counts against (`MailQuota`): its cache key,
 * the effective limits, and the timezone whose midnight closes the daily
 * window. `shared` says the brand sends through the application's default
 * mailer and therefore shares `key` — and the count behind it — with every
 * other brand that does.
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
