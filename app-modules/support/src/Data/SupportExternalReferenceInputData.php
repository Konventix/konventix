<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `PATCH /admin/support-requests/{request}/external-reference` (design §9.2).
 *
 * Free text, and the module's SPEC fixes it as free text: "a request for a
 * GitLab integration is a request for an external tool the operator deep
 * links". So no URL rule, no issue-number pattern, no validation that would
 * quietly make this column mean one tracker.
 *
 * An empty string clears it — the Action normalises blank to null, so the
 * field behaves the way a text input does.
 */
#[TypeScript]
final class SupportExternalReferenceInputData extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(255)]
        public readonly ?string $externalReference,
    ) {}
}
