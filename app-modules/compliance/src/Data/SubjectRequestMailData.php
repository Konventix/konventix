<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind Compliance's subject-facing notices (ADR-0009 —
 * never a free-form array). One shape for all three, because they say the
 * same four things in different tones: which request, by when, what happened,
 * and where to look.
 */
final class SubjectRequestMailData extends Data
{
    public function __construct(
        public readonly string $requestType,
        public readonly string $deadline,
        public readonly ?string $availableUntil = null,
        public readonly ?string $reasonCode = null,
    ) {}
}
