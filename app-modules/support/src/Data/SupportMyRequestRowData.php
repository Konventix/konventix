<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Carbon\CarbonImmutable;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of "My support requests" (design §8.1).
 *
 * Its own shape rather than the console's {@see SupportInboxRowData}, and
 * the difference is the whole point: the reporter's list carries no brand
 * label, no assignee, no known-issue mark and no masked reporter — every
 * one of those is a fact about *handling* the report, and a person reading
 * their own list is not handling it. What is left is what they asked for:
 * the reference they can quote, what they said it was about, where it
 * stands, when they filed it and when something last happened.
 *
 * `lastUpdateAt` is the newest of the request's own `updated_at` and its
 * last **external** message. An internal note must not move it: a list that
 * says "updated an hour ago" when the only thing that happened is a staff
 * note the reporter will never see is a list that invites them to open a
 * page where nothing changed.
 */
#[TypeScript]
final class SupportMyRequestRowData extends Data
{
    /**
     * @param  list<SupportCategory>  $categories
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly array $categories,
        public readonly SupportRequestState $state,
        public readonly ?string $createdAt,
        public readonly ?string $lastUpdateAt,
    ) {}

    public static function ofRequest(SupportRequest $request, ?CarbonImmutable $lastExternalMessageAt): self
    {
        $lastUpdate = $request->updated_at;

        if ($lastExternalMessageAt !== null && ($lastUpdate === null || $lastExternalMessageAt->greaterThan($lastUpdate))) {
            $lastUpdate = $lastExternalMessageAt;
        }

        return new self(
            id: (string) $request->getKey(),
            reference: $request->reference(),
            categories: $request->categoryCases(),
            state: $request->state,
            createdAt: $request->created_at?->toIso8601String(),
            lastUpdateAt: $lastUpdate?->toIso8601String(),
        );
    }
}
