<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

use Carbon\CarbonImmutable;

/**
 * The verdict "this mail waits": when the next free window opens and which
 * window (`hour` or `day`) closed it — the later of the two when both are
 * exhausted, since the mail cannot leave before either reopens.
 */
final readonly class MailQuotaHold
{
    public function __construct(
        public CarbonImmutable $until,
        public string $window,
    ) {}
}
