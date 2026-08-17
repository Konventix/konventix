<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

/**
 * What a push attempt came back as (ADR-0041 point 7). `expired` is the
 * signal that matters beyond success: the push service says the endpoint
 * is gone (404/410), so the subscription row must be pruned.
 */
final readonly class PushDeliveryReport
{
    public function __construct(
        public bool $success,
        public bool $expired,
        public ?string $reason = null,
    ) {}
}
