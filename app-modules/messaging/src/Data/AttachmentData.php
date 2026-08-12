<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * One attachment on an outbound message, referenced by disk + path rather
 * than raw bytes — the queued job payload stays small, and the file is
 * read at send time by the channel. The first consumer is Commerce's
 * invoice mail attaching the archived ZUGFeRD PDF (ADR-0031: what the
 * recipient gets is the sealed archive copy, byte-identical).
 */
final class AttachmentData extends Data
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $as,
        public readonly ?string $mime = null,
    ) {}
}
