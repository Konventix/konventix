<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The `messaging.test-mail` template's payload (Task 8, ADR-0048 decision
 * 4/6): the operator's own "Send test mail to me" — proves a brand's sender
 * identity and the branded layout render, nothing more.
 */
final class TestMailData extends Data
{
    public function __construct(
        public readonly string $brandName,
        public readonly string $date,
    ) {}
}
