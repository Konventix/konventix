<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;

/**
 * The operator-facing escalation payload: which request, how long is left,
 * and how many modules have still not answered — the three facts that decide
 * whether anyone needs to do something today.
 */
final class DeadlineWarningMailData extends Data
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $requestType,
        public readonly string $deadline,
        public readonly int $daysLeft,
        public readonly int $openModules,
    ) {}
}
