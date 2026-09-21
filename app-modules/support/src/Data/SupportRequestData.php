<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A report as every surface renders it (ADR-0009) — the console inbox and
 * detail page, "My support requests", and the guest's signed page all read
 * this one shape.
 *
 * **Contact data is not on it.** `email` and the reporter's account id are
 * behind `support.view-reporter-contact` and every unmasked view is audited
 * (§10.8), so they are fetched deliberately by the surface that may show
 * them rather than carried along by default and forgotten about in a prop
 * dump. `reporterLabel` is the masked stand-in every surface may print.
 *
 * `reference` is the last eight characters of the ULID, upper-cased: what
 * the toast, the receipt mail and the alert annotation all quote, so a
 * person and an operator can say the same word out loud.
 */
#[TypeScript]
final class SupportRequestData extends Data
{
    /**
     * @param  list<SupportCategory>  $categories
     * @param  list<SupportRequestMessageData>  $messages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly ?string $filedOnBrandId,
        public readonly array $categories,
        public readonly string $message,
        public readonly SupportRequestState $state,
        public readonly bool $isKnownIssue,
        public readonly ?string $knownIssueNotice,
        public readonly ?string $externalReference,
        public readonly ?string $assignedToUserId,
        public readonly string $reporterLabel,
        public readonly bool $contactConsent,
        public readonly SupportContextData $context,
        #[DataCollectionOf(SupportRequestMessageData::class)]
        public readonly array $messages,
        public readonly ?string $seenAt,
        public readonly ?string $closedAt,
        public readonly ?string $createdAt,
    ) {}

    /**
     * @param  list<SupportRequestMessageData>  $messages
     */
    public static function fromModel(SupportRequest $request, array $messages = []): self
    {
        return new self(
            id: $request->getKey(),
            reference: $request->reference(),
            filedOnBrandId: $request->filed_on_brand_id,
            categories: $request->categoryCases(),
            message: $request->message,
            state: $request->state,
            isKnownIssue: $request->is_known_issue,
            knownIssueNotice: $request->known_issue_notice,
            externalReference: $request->external_reference,
            assignedToUserId: $request->assigned_to_user_id,
            reporterLabel: $request->maskedReporter(),
            contactConsent: $request->contact_consent,
            context: $request->context,
            messages: $messages,
            seenAt: $request->seen_at?->toIso8601String(),
            closedAt: $request->closed_at?->toIso8601String(),
            createdAt: $request->created_at?->toIso8601String(),
        );
    }
}
