<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * One attachment on an outbound message, in one of two forms.
 *
 * The disk form (`disk` + `path`) is the original contract: the queued job
 * payload stays small, and the file is read at send time by the channel.
 * The first consumer is Commerce's invoice mail attaching the archived
 * ZUGFeRD PDF (ADR-0031: what the recipient gets is the sealed archive
 * copy, byte-identical) — that call site is unchanged by the form below.
 *
 * The inline form (`content`) exists for a file that is derived, tiny and
 * regenerable — the calendar file Event's public route and Registration's
 * confirmation mail both produce (spec §6.3) — where writing it to a disk
 * would buy a cache-invalidation problem for nothing. `as` leads the
 * constructor because it is the only field both forms need, and every call
 * site uses named arguments (`Commerce\Actions\SendInvoiceMail` already
 * does).
 *
 * Exactly one form must be set: both or neither is refused here, in the
 * constructor, so a caller cannot build a half-specified attachment that
 * only fails once `TemplatedMail::attachments()` tries to read it. Inline
 * content is capped at `MAX_INLINE_BYTES` so the disk form stays the only
 * way to attach a document — nobody grows a queued job payload by
 * attaching a PDF this way.
 */
final class AttachmentData extends Data
{
    public const int MAX_INLINE_BYTES = 64 * 1024;

    public function __construct(
        public readonly string $as,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        public readonly ?string $content = null,
        public readonly ?string $mime = null,
    ) {
        $diskForm = $this->disk !== null || $this->path !== null;
        $inlineForm = $this->content !== null;

        if ($diskForm && $inlineForm) {
            throw new InvalidArgumentException(
                'AttachmentData must set either a disk and path, or inline content — not both.',
            );
        }

        if (! $diskForm && ! $inlineForm) {
            throw new InvalidArgumentException(
                'AttachmentData must set either a disk and path, or inline content.',
            );
        }

        if ($diskForm && ($this->disk === null || $this->path === null)) {
            throw new InvalidArgumentException(
                "AttachmentData's disk form needs both disk and path.",
            );
        }

        if ($inlineForm && strlen($this->content) > self::MAX_INLINE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'AttachmentData inline content is %d bytes, over the %d KiB ceiling.',
                strlen($this->content),
                self::MAX_INLINE_BYTES / 1024,
            ));
        }
    }
}
