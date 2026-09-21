<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The console's detail page, in one shape (design §9.2, ADR-0009).
 *
 * It wraps {@see SupportRequestData} — which already carries the report,
 * its context and its thread — rather than widening it, because everything
 * added here is a fact about *this reader on this host* and not about the
 * report:
 *
 * - `reporterContact` is null unless the reader holds
 *   `support.view-reporter-contact`, and the controller records the
 *   disclosure through `AuditRecorder` in the same breath as filling it
 *   (§10.8). A field that is sometimes there is the honest shape for a
 *   capability that is sometimes held; hiding the address in the template
 *   instead would ship it to every browser that asked.
 * - `allowedNext` comes from `SupportRequestState::allowedNext()`, so the
 *   page offers exactly the moves `TransitionSupportRequest` will accept
 *   and nobody is shown a button that answers 422.
 * - `pageLogsUrl` / `submitLogsUrl` are built from
 *   `config('support.logs.correlation_url')`. Null when the deployment has
 *   configured no logs dashboard, and the page then prints the ids without
 *   a link rather than a link to nowhere.
 *
 * `brandLabel` is the brand host the report arrived on, or `Console`
 * (ADR-0063) — resolved by the controller, the only thing in this slice
 * that may read Platform's `Brand`.
 */
#[TypeScript]
final class SupportRequestDetailData extends Data
{
    /**
     * @param  list<SupportRequestState>  $allowedNext
     */
    public function __construct(
        public readonly SupportRequestData $request,
        public readonly string $brandLabel,
        public readonly ?string $reporterContact,
        public readonly array $allowedNext,
        public readonly ?string $pageLogsUrl,
        public readonly ?string $submitLogsUrl,
    ) {}

    /**
     * @param  list<SupportRequestMessageData>  $messages
     */
    public static function ofRequest(
        SupportRequest $request,
        string $brandLabel,
        ?string $reporterContact,
        array $messages,
        ?string $pageLogsUrl,
        ?string $submitLogsUrl,
    ): self {
        return new self(
            request: SupportRequestData::fromModel($request, $messages),
            brandLabel: $brandLabel,
            reporterContact: $reporterContact,
            allowedNext: $request->state->allowedNext(),
            pageLogsUrl: $pageLogsUrl,
            submitLogsUrl: $submitLogsUrl,
        );
    }
}
