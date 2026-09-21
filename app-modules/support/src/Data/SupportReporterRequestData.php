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
 * One report as its own reporter reads it — the account page and the
 * guest's signed page render this one shape (design §8).
 *
 * **Not {@see SupportRequestData}.** That shape is the console's: it
 * carries `externalReference`, `assignedToUserId`, `isKnownIssue`,
 * `knownIssueNotice` and `contactConsent`, all of which are facts about how
 * the operator is handling the report and none of which is the reporter's
 * business. A page that filtered them out in the template would still have
 * shipped them to the browser; a separate shape cannot.
 *
 * `messages` is filled by the controller from
 * `SupportRequestMessage::scopeVisibleToReporter()` and carries external
 * entries only. The visibility column still travels with each entry rather
 * than being stripped, because the reporter's thread component and the
 * console's read the same {@see SupportRequestMessageData} — one shape, one
 * place for that filter to be right.
 *
 * `canReply` is `! state->isTerminal()`, computed here rather than in the
 * template so the page and `PostReporterReply` answer the same question the
 * same way.
 */
#[TypeScript]
final class SupportReporterRequestData extends Data
{
    /**
     * @param  list<SupportCategory>  $categories
     * @param  list<SupportRequestMessageData>  $messages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly array $categories,
        public readonly string $message,
        public readonly SupportRequestState $state,
        public readonly SupportContextData $context,
        #[DataCollectionOf(SupportRequestMessageData::class)]
        public readonly array $messages,
        public readonly bool $canReply,
        public readonly ?string $createdAt,
        public readonly ?string $closedAt,
    ) {}

    /**
     * @param  list<SupportRequestMessageData>  $messages
     */
    public static function ofRequest(SupportRequest $request, array $messages): self
    {
        return new self(
            id: (string) $request->getKey(),
            reference: $request->reference(),
            categories: $request->categoryCases(),
            message: $request->message,
            state: $request->state,
            context: $request->context,
            messages: $messages,
            canReply: ! $request->state->isTerminal(),
            createdAt: $request->created_at?->toIso8601String(),
            closedAt: $request->closed_at?->toIso8601String(),
        );
    }
}
