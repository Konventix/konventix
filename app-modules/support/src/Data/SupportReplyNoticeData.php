<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind `support.request-replied` (design §7): somebody
 * has written back about the report.
 *
 * `excerpt` is the beginning of the reply, not the whole of it. A mail that
 * carried the answer in full would be the place people read it, and the
 * thread — where the next question can be asked and where the whole
 * exchange is kept — would be the place nobody goes. It also keeps the one
 * sentence that leaves the row short enough to read on a lock screen, which
 * is where "your purchase problem has an answer" wants to be read.
 */
final class SupportReplyNoticeData extends Data
{
    public function __construct(
        public readonly string $reference,
        public readonly string $excerpt,
        /** Absolute: the account page, or the guest's signed link. */
        public readonly string $requestUrl,
    ) {}
}
