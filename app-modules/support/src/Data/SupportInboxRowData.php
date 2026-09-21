<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the console inbox (design §9.1).
 *
 * Its own shape rather than {@see SupportRequestData}: a list of a hundred
 * reports has no business carrying a hundred `context` snapshots and a
 * hundred threads across the wire, and the row the operator scans is
 * genuinely a different thing from the report they then open.
 *
 * **`brandLabel` is resolved by the controller, not by this class.** The
 * inbox is read on the admin host, which resolves no brand (ADR-0029), and
 * the brand names are one query for the whole page rather than one per row.
 * A report filed on the operator console has no brand at all (ADR-0063) and
 * reads `Console` — the same word `SupportContextData::CONSOLE_BRAND` uses,
 * so the inbox, the metric label and the snapshot all say it the same way.
 *
 * `reporterLabel` is the masked stand-in ({@see SupportRequest::maskedReporter()}).
 * The unmasked address lives on the detail page behind
 * `support.view-reporter-contact` and is audited on every view (§10.8); a
 * list that showed it would audit a hundred disclosures for one glance, or
 * — worse — none.
 */
#[TypeScript]
final class SupportInboxRowData extends Data
{
    /**
     * @param  list<SupportCategory>  $categories
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly array $categories,
        public readonly SupportRequestState $state,
        public readonly string $brandLabel,
        public readonly ?string $release,
        public readonly string $reporterLabel,
        public readonly ?string $assignedToUserId,
        public readonly bool $isKnownIssue,
        public readonly ?string $createdAt,
    ) {}

    public static function ofRequest(SupportRequest $request, string $brandLabel): self
    {
        return new self(
            id: (string) $request->getKey(),
            reference: $request->reference(),
            categories: $request->categoryCases(),
            state: $request->state,
            brandLabel: $brandLabel,
            release: $request->context->release,
            reporterLabel: $request->maskedReporter(),
            assignedToUserId: $request->assigned_to_user_id,
            isKnownIssue: $request->is_known_issue,
            createdAt: $request->created_at?->toIso8601String(),
        );
    }
}
