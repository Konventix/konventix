<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The typed web-push payload (ADR-0041 point 7): title, body, URL, tag —
 * nothing else crosses to the service worker. LanCore's free-form push
 * contract is the counterexample this shape exists to prevent.
 */
final class PushMessageData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly ?string $tag,
    ) {}
}
