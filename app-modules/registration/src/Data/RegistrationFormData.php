<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\RegistrationType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Inertia props for the public registration form (`GET
 * /events/{event}/register/{type}`, ADR-0009). The single source for what
 * the page is allowed to know about the offer it renders — never an inline
 * `->map(fn ...)` array in the controller.
 */
#[TypeScript]
final class RegistrationFormData extends Data
{
    public function __construct(
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $name,
        public readonly string $settlement,
        public readonly string $confirmationGate,
        public readonly ?PriceDeclarationData $price,
        public readonly int $termsDays,
    ) {}

    public static function fromModel(RegistrationType $registrationType): self
    {
        return new self(
            registrationTypeId: $registrationType->id,
            eventId: $registrationType->event_id,
            name: $registrationType->name,
            settlement: $registrationType->settlement->value,
            confirmationGate: $registrationType->confirmation_gate->value,
            // The declared price is an intention, not a guarantee (ADR-0017):
            // the invoice's actual treatment is resolved by Commerce.
            price: $registrationType->price_declaration,
            termsDays: $registrationType->terms_days,
        );
    }
}
