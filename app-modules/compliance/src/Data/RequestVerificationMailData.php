<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind the public page's verification mail (ADR-0009 —
 * never a free-form array). Sent before any `DataSubjectRequest` exists: the
 * link, not a request, is what this message is about (ADR-0050 §4).
 */
final class RequestVerificationMailData extends Data
{
    public function __construct(
        public readonly string $requestType,
        public readonly string $verifyUrl,
        public readonly int $expiresInHours,
    ) {}
}
