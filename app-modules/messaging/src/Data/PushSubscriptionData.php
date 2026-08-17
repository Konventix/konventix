<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;

/**
 * What the browser hands over when it subscribes this device to push
 * (ADR-0041 point 7) — the flattened shape of
 * `PushSubscription.toJSON()`, one `Data` class as validation and payload
 * contract at once (ADR-0009).
 */
final class PushSubscriptionData extends Data
{
    public function __construct(
        #[Required, StringType, Url, Max(1000)]
        public readonly string $endpoint,

        #[Required, StringType, Max(255)]
        public readonly string $p256dh,

        #[Required, StringType, Max(255)]
        public readonly string $auth,

        #[In(['aesgcm', 'aes128gcm'])]
        public readonly string $contentEncoding = 'aes128gcm',
    ) {}
}
