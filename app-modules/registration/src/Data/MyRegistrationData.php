<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\Registration;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the signed-in account's own registrations, as its owner sees it
 * (ADR-0009). The organizer's list is a different shape and stays that way:
 * this one carries no reviewer, no reason code and no other registrant.
 *
 * `eventName` comes from Registration's own `EventSummary` read model, never
 * from Event's tables (ADR-0004) — and falls back to the ULID rather than to
 * an empty string, because a registration whose event summary has not
 * arrived yet is still the registrant's, and still has to render.
 */
#[TypeScript]
final class MyRegistrationData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly string $typeName,
        public readonly string $status,
        public readonly string $paymentStatus,
        public readonly ?string $organizationName,
        public readonly string $submittedAt,
        public readonly ?string $confirmedAt,
    ) {}

    /**
     * Named `of*` rather than `from*`: laravel-data routes every public
     * static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofModel(Registration $registration, ?string $eventName, ?string $typeName): self
    {
        return new self(
            id: $registration->getKey(),
            eventId: $registration->event_id,
            eventName: $eventName ?? $registration->event_id,
            typeName: $typeName ?? '—',
            status: $registration->status->value,
            paymentStatus: $registration->payment_status->value,
            organizationName: $registration->organization_name,
            submittedAt: $registration->submitted_at->format('Y-m-d H:i'),
            confirmedAt: $registration->confirmed_at?->format('Y-m-d H:i'),
        );
    }
}
