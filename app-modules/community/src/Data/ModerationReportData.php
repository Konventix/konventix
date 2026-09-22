<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One open report in the desk's queue (spec §4.4, D-COM-01), with the line
 * it names. The desk reads the body even of a line it has already removed
 * — "the desk can see what it acted on" (spec §4.2) — which is exactly what
 * participants never receive; `removedAt` says whether it still stands.
 */
#[TypeScript]
final class ModerationReportData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $messageId,
        public readonly string $authorUserId,
        public readonly ?string $authorDisplayName,
        public readonly string $body,
        public readonly ?string $removedAt,
        public readonly ?string $reporterDisplayName,
        public readonly ?string $note,
        public readonly string $reportedAt,
    ) {}
}
