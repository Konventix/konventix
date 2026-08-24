<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Models\RegistrationType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One offer as a visitor sees it on the public event landing page, before
 * they have an account (ADR-0009 — the page is never handed a raw model or
 * an inline `->map(fn ...)` array).
 *
 * Deliberately narrower than `RegistrationFormData`: `confirmationGate` and
 * `termsDays` describe what happens *after* someone commits, and a landing
 * page that recites them is answering a question nobody has asked yet. What
 * survives is what decides a click — the name, the price, and whether a
 * reviewer stands between the form and a place at the event.
 */
#[TypeScript]
final class PublicOfferData extends Data
{
    public function __construct(
        public readonly string $registrationTypeId,
        public readonly string $name,
        public readonly string $url,
        public readonly ?PriceDeclarationData $price,
        public readonly bool $requiresApproval,
    ) {}

    /**
     * Named `of*` rather than `from*` on purpose: laravel-data routes every
     * public static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofType(RegistrationType $registrationType): self
    {
        return new self(
            registrationTypeId: $registrationType->id,
            name: $registrationType->name,
            // Relative, because the landing page is rendered on the brand's
            // own host and an absolute URL would pin the link to whichever
            // domain the request happened to arrive on.
            url: route('registration.show', [
                'event_ulid' => $registrationType->event_id,
                'type' => $registrationType->id,
            ], absolute: false),
            // An intention, not a guarantee (ADR-0017): Commerce resolves the
            // actual tax treatment when it settles.
            price: $registrationType->price_declaration,
            requiresApproval: $registrationType->approval === RegistrationApproval::Required,
        );
    }
}
