<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind Compliance's subject-facing notices (ADR-0009 —
 * never a free-form array). One shape for all three, because they say the
 * same four things in different tones: which request, by when, what happened,
 * and where to look.
 *
 * `downloadUrl` is set only for an account-less subject's EXPORT_READY mail
 * (ADR-0050 §9): an account-backed subject is pointed at Settings → Privacy
 * as before, because a link that carries its own authority is exactly what
 * that path avoids. A subject with no account has no settings page to sign
 * into, so the alternative here is not a worse link — it is no delivery at
 * all.
 */
final class SubjectRequestMailData extends Data
{
    public function __construct(
        public readonly string $requestType,
        public readonly string $deadline,
        public readonly ?string $availableUntil = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $downloadUrl = null,
    ) {}
}
