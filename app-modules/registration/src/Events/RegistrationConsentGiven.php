<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registrant accepted the legal texts shown on the registration form
 * (spec §8, D-CPL-10) — Registration's half of the declaration contract,
 * beside Commerce's `CheckoutConsentGiven`.
 *
 * `bindingContext` is an opaque bag of scalars: the brand, the event, the
 * registration types and the entry conditions this act covers, plus the
 * registration itself. Registration builds it and never interprets it;
 * Compliance resolves it into policy versions and writes the consent rows
 * (Task 32, `RecordConsentDeclaration`). Until that listener exists, nothing
 * consumes this event — which is what keeps this slice behaviour-neutral.
 *
 * Scalars only (ADR-0004): the bag is a nested array of strings and lists of
 * strings, never an object of another module's making.
 *
 * @phpstan-type BindingContextPayload array{brand_id: string, event_id?: string|null, registration_type_ids?: list<string>, entry_condition_ids?: list<string>, settlement_provider?: string|null, registration_id?: string|null, order_id?: string|null}
 */
final readonly class RegistrationConsentGiven
{
    /**
     * @param  BindingContextPayload  $bindingContext
     */
    public function __construct(
        public string $brandId,
        public string $subjectId,
        public string $captureContext,
        public string $acceptedAt,
        public array $bindingContext,
    ) {}
}
