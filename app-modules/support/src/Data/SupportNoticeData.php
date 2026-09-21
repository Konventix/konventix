<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind `support.request-notice` (design §7): the notice
 * every triage holder gets, in the bell and by mail, the moment a report
 * arrives.
 *
 * Deliberately the same three-field shape as Commerce's
 * `OperationsNoticeData` — title, detail, absolute console URL — and for
 * the same reason: the sending listener composes the sentences, so the
 * template stays branch-free and a brand's override of it can never grow
 * an expression (`TemplateOverrideGuard`). What is *not* shared is the
 * topic key. D-SUP-06 is explicit that a support report never rides on
 * `commerce.operations`; Support declares its own topic and this is its
 * own payload.
 *
 * `detail` quotes the beginning of what the person wrote. That is personal
 * data leaving the row on purpose, to the people whose job is to read it —
 * and it is why the same text never goes near a log record or a metric
 * label (design §11).
 */
final class SupportNoticeData extends Data
{
    public function __construct(
        public readonly string $reference,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $url,
    ) {}
}
