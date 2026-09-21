<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `PATCH /admin/support-requests/{request}/known-issue` (design §9.2, §9.4).
 *
 * The flag and the sentence travel together because they are one decision:
 * marking a report as the canonical instance of a problem is only useful if
 * the next visitor is told something, and the `notice` is the only text in
 * this module a stranger ever reads.
 *
 * 500 characters is the column's width, and it is generous on purpose — the
 * banner is a sentence, not a status page. The notice may be null: triage
 * marks first and writes later, and `KnownIssueData` falls back to the
 * category label so the banner is never blank in between.
 */
#[TypeScript]
final class SupportKnownIssueInputData extends Data
{
    public function __construct(
        #[Required, BooleanType]
        public readonly bool $isKnownIssue,
        #[Nullable, StringType, Max(500)]
        public readonly ?string $knownIssueNotice = null,
    ) {}
}
